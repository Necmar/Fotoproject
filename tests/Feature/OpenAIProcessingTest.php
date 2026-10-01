<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Image;
use App\Models\ImageProcessingRecord;
use App\Models\User;
use App\Services\OpenAI\EditInstructionBuilder;
use App\Services\OpenAI\ImageEditService;
use App\Services\Processing\ImagePipeline;
use App\Services\SystemSettings;
use App\Support\BatchSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Phase 5: OpenAI analysis, edit policy, integrity verification, retries and fallback. All HTTP is faked. */
class OpenAIProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        config([
            'queue.default' => 'database',
            'bora.queue.backoff' => [0, 0, 0],
            'services.openai.key' => 'sk-test-secret-key',
            'services.openai.analysis_model' => 'gpt-5.4-mini',
            'services.openai.image_model' => 'gpt-image-2',
        ]);
        $this->user = User::factory()->create();
    }

    private function batch(array $settings = [], int $photos = 1, int $width = 400): Batch
    {
        $id = $this->actingAs($this->user)->postJson('/api/company/batches', $settings + ['name' => 'Golf 8'])->json('data.id');

        for ($i = 0; $i < $photos; $i++) {
            // Small photos keep the tests fast; proportions as a real 4:3 photo.
            $h = intdiv($width * 3, 4);
            $img = imagecreatetruecolor($width, $h);
            imagefill($img, 0, 0, imagecolorallocate($img, 90 + 30 * $i, 110, 130));
            imagefilledrectangle($img, intdiv($width, 4), intdiv($h, 4), intdiv($width * 3, 4), intdiv($h * 3, 4), imagecolorallocate($img, 200, 30, 30));
            ob_start();
            imagejpeg($img, null, 90);
            $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->createWithContent("{$i}.jpg", ob_get_clean())])->assertCreated();
        }

        $this->postJson("/api/company/batches/{$id}/start")->assertOk();

        return Batch::query()->findOrFail($id);
    }

    private function analysis(array $override = []): array
    {
        return array_replace_recursive([
            'product' => ['description' => 'red hatchback car', 'category' => 'car', 'short_name' => 'Volkswagen Golf 8'],
            'product_box' => ['x' => 0.2, 'y' => 0.2, 'w' => 0.6, 'h' => 0.6],
            'issues' => array_fill_keys(['too_dark', 'too_bright', 'blurry', 'motion_blur', 'noisy', 'poor_white_balance', 'low_contrast', 'low_quality', 'crooked', 'distracting_background', 'harsh_shadows'], false),
            'severity' => 'minor',
            'restorable' => true,
            'people' => ['present' => false, 'count' => 0, 'overlaps_product' => false],
            'visible' => ['license_plate' => true, 'text_or_logos' => true, 'damage' => true, 'damage_description' => 'scratch on rear bumper'],
            'corrections' => ['exposure' => 0.3, 'contrast' => 0.2, 'warmth' => 0.1, 'tint' => 0, 'sharpen' => 0.3, 'denoise' => 0, 'rotate_degrees' => 0],
            'needs_generative_edit' => false,
            'edit_instructions' => 'Brighten slightly and balance the shadows.',
        ], $override);
    }

    private function responsesBody(array $json, int $in = 1200, int $out = 300): array
    {
        return [
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json)]]]],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ];
    }

    private function verification(bool $ok = true, bool $peopleVisible = false): array
    {
        return [
            'product_identity_changed' => false, 'product_colour_changed' => ! $ok, 'damage_hidden_or_changed' => false,
            'text_or_logos_altered' => false, 'license_plate_altered' => false, 'product_looks_fake' => false,
            'people_still_visible' => $peopleVisible, 'notes' => $ok ? 'identical' : 'colour shifted',
            'reason_nl' => $ok ? '' : 'de lakkleur is veranderd', 'reason_en' => $ok ? '' : 'the paint colour changed',
        ];
    }

    /**
     * Fake image edit. A cut-out request gets the test product (red box, same
     * place as in the uploaded photo) on the requested key colour; any other
     * edit gets a new background, with the product drawn in $productColour.
     */
    private function editBody(?Request $request = null, array $productColour = [200, 30, 30]): array
    {
        $body = $request ? (string) $request->body() : '';
        if (str_contains($body, 'Cut out the product') && preg_match('/#([0-9A-F]{6})/', $body, $m)) {
            [$r, $g, $b] = sscanf($m[1], '%02x%02x%02x');
            $img = imagecreatetruecolor(400, 300);
            imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
            imagefilledrectangle($img, 100, 75, 300, 225, imagecolorallocate($img, 200, 30, 30));
        } else {
            // Larger than the photo (as the model may answer): never upscaled.
            $img = imagecreatetruecolor(512, 384);
            imagefill($img, 0, 0, imagecolorallocate($img, 150, 160, 170));
            imagefilledrectangle($img, 128, 96, 384, 288, imagecolorallocate($img, ...$productColour));
        }
        ob_start();
        imagepng($img);

        return [
            'data' => [['b64_json' => base64_encode(ob_get_clean())]],
            'usage' => ['input_tokens' => 1500, 'input_tokens_details' => ['text_tokens' => 500, 'image_tokens' => 1000], 'output_tokens' => 4000],
        ];
    }

    /** Fake OpenAI: analysis and verification are told apart by the schema name. */
    private function fakeOpenAI(array $analysis, ?array $verification = null, ?callable $edit = null): void
    {
        Http::fake(function (Request $request) use ($analysis, $verification, $edit) {
            if (str_ends_with($request->url(), '/responses')) {
                $name = $request['text']['format']['name'] ?? '';

                return Http::response($this->responsesBody($name === 'edit_verification' ? ($verification ?? $this->verification()) : $analysis));
            }

            if (str_ends_with($request->url(), '/images/edits')) {
                return $edit ? $edit($request) : Http::response($this->editBody($request));
            }

            return Http::response([], 404);
        });
    }

    private function sentTo(string $endpoint): int
    {
        return count(Http::recorded(fn (Request $r) => str_ends_with($r->url(), $endpoint)));
    }

    public function test_every_photo_gets_the_fixed_retouch_prompt_even_when_subtle(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['strength' => 'subtle']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value);
        $this->assertSame('edited', $image->ai_status);
        $this->assertSame(2, $this->sentTo('/responses'), 'analysis + verification');
        $this->assertSame(1, $this->sentTo('/images/edits'));
        $body = (string) Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/images/edits'))->first()[0]->body();
        $this->assertStringContainsString('Bewerk deze originele autofoto naar professionele verkoopfotografie', $body);
        $this->assertStringContainsString('Geen verwijdering van permanente beschadigingen.', $body);
        $this->assertStringContainsString('Sterkte: subtiel', $body);
        $this->assertSame('red hatchback car', $image->analysis['ai']['product']['description']);

        // Key only in the Authorization header; the photo is sent as a data URL; structured output requested.
        $request = Http::recorded()[0][0];
        $this->assertSame('Bearer sk-test-secret-key', $request->header('Authorization')[0]);
        $this->assertSame('gpt-5.4-mini', $request['model']);
        $this->assertSame('json_schema', $request['text']['format']['type']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $request['input'][1]['content'][1]['image_url']);

        $record = ImageProcessingRecord::query()->where('type', 'analysis')->firstOrFail();
        $this->assertSame('openai', $record->provider);
        $this->assertSame(1200, $record->input_tokens);
        // 1200 * 0.75 + 300 * 4.50 per million
        $this->assertEqualsWithDelta(0.00225, (float) $record->estimated_cost_usd, 0.00001);
    }

    public function test_super_admin_can_replace_the_fixed_prompt(): void
    {
        app(\App\Services\SystemSettings::class)->update(['retouch_prompt' => 'Eigen vaste opdracht van de beheerder.']);
        $this->fakeOpenAI($this->analysis());
        $this->batch(['strength' => 'normal']);

        $this->artisan('bora:work')->assertSuccessful();

        $body = (string) Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/images/edits'))->first()[0]->body();
        $this->assertStringContainsString('Eigen vaste opdracht van de beheerder.', $body);
        $this->assertStringNotContainsString('Bewerk deze originele autofoto', $body);
        $this->assertStringContainsString('Sterkte: normaal', $body);
    }

    public function test_strong_strength_edits_verifies_and_uses_the_ai_result(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['strength' => 'strong', 'resolution' => '2000']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value);
        $this->assertSame('edited', $image->ai_status);
        $this->assertNotNull($image->ai_path);
        Storage::disk('local')->assertExists($image->ai_path);
        $this->assertSame(2, $this->sentTo('/responses'), 'analysis + verification');
        // Original background: one whole-photo retouch (the clean advertisement look), verified.
        $this->assertSame(1, $this->sentTo('/images/edits'));
        $edits = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/images/edits'));
        $this->assertStringContainsString('Bewerk deze originele autofoto', (string) $edits->first()[0]->body());

        // At the photo's own size (400 px): never upscaled to 2000.
        $this->assertSame(400, max($image->output_width, $image->output_height));

        $edit = $edits->last()[0];
        $body = (string) $edit->body();
        $this->assertStringContainsString('gpt-image-2', $body);
        $this->assertStringContainsString('Behoud permanente gebruikssporen', $body);
        $this->assertStringContainsString('Er is een kenteken zichtbaar', $body);
        $this->assertStringContainsString('Sterkte: sterk', $body);
        $this->assertStringContainsString('scratch on rear bumper', $body);
        $this->assertStringContainsString('400x304', $body, 'size follows the working copy (multiples of 16)');
        $this->assertMatchesRegularExpression('/name="quality"\s+(Content-Length: \d+\s+)?high/', $body, 'retouch in high quality');
        $this->assertStringContainsString('input_fidelity', $body, 'stay close to the source photo');

        $this->assertSame(3, ImageProcessingRecord::query()->where('provider', 'openai')->count(), 'analysis, retouch, verification');
        $editRecord = ImageProcessingRecord::query()->where('type', 'edit')->firstOrFail();
        // 500 text * 2.50 + 1000 image * 4.00 + 4000 out * 15.00 per million
        $this->assertEqualsWithDelta(0.06525, (float) $editRecord->estimated_cost_usd, 0.00001);
    }

    public function test_edit_that_changes_the_product_is_rejected_when_configured(): void
    {
        config(['services.openai.on_product_change' => 'reject']);
        $this->fakeOpenAI($this->analysis(), $this->verification(ok: false));
        $batch = $this->batch(['strength' => 'strong']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value);
        $this->assertSame('edit_rejected', $image->ai_status);
        $this->assertNull($image->ai_path);
        $this->assertSame([], Storage::disk('local')->files($batch->storageDirectory().'/ai'));
        $this->assertContains('ai_edit_rejected_reason', array_column($image->warnings, 'code'));
        $this->assertStringContainsString('de lakkleur is veranderd', collect($this->getJson("/api/company/batches/{$image->batch_id}")->json('data.images.0.warnings'))->pluck('message')->implode(' '));
    }

    public function test_a_background_edit_never_repaints_the_retouched_product(): void
    {
        // The background edit "repaints" the product blue; the result must still show the (retouched) red product.
        $this->fakeOpenAI(
            $this->analysis(['corrections' => array_fill_keys(['exposure', 'contrast', 'warmth', 'tint', 'sharpen', 'denoise', 'rotate_degrees'], 0)]),
            null,
            fn (Request $r) => Http::response(str_contains((string) $r->body(), 'autofoto') ? $this->editBody($r) : $this->editBody($r, [30, 60, 220])),
        );
        $batch = $this->batch(['strength' => 'strong', 'background' => 'remove_distractions']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('edited', $image->ai_status);
        $out = imagecreatefromstring(Storage::disk('local')->get($image->ai_path));
        $c = imagecolorat($out, 200, 150); // centre of the product
        $this->assertGreaterThan(170, ($c >> 16) & 0xFF, 'red channel of the original product');
        $this->assertLessThan(80, $c & 0xFF, 'not the blue the model painted');
        // Background (outside the product) comes from the edit.
        $bg = imagecolorat($out, 25, 25);
        $this->assertEqualsWithDelta(150, ($bg >> 16) & 0xFF, 12);
    }

    public function test_neutral_and_removed_backgrounds_are_composited_locally(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['strength' => 'subtle', 'background' => 'remove', 'output_format' => 'png']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('edited', $image->ai_status);
        $this->assertSame(2, $this->sentTo('/images/edits'), 'retouch + cut-out');
        // The intermediate retouch is not kept.
        $this->assertCount(1, Storage::disk('local')->files($batch->storageDirectory().'/ai'));
        $png = imagecreatefromstring(Storage::disk('local')->get($image->ai_path));
        $this->assertSame(127, (imagecolorat($png, 12, 12) >> 24) & 0x7F, 'transparent background');
        $this->assertSame(0, (imagecolorat($png, 200, 150) >> 24) & 0x7F, 'opaque product');
    }

    public function test_a_transparent_cutout_still_gives_a_reliable_mask(): void
    {
        // Some models answer a cut-out with a transparent PNG (transparent pixels read as black).
        $this->fakeOpenAI($this->analysis(), null, function (Request $r) {
            $this->assertStringContainsString('opaque', (string) $r->body());
            $img = imagecreatetruecolor(400, 300);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
            imagefilledrectangle($img, 100, 75, 300, 225, imagecolorallocatealpha($img, 200, 30, 30, 0));
            ob_start();
            imagepng($img);

            return Http::response(['data' => [['b64_json' => base64_encode(ob_get_clean())]], 'usage' => []]);
        });
        $batch = $this->batch(['strength' => 'subtle', 'background' => 'neutral']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('edited', $image->ai_status);
        $this->assertNotContains('ai_cutout_failed', array_column($image->warnings ?? [], 'code'));
    }

    public function test_a_cutout_that_misses_the_product_falls_back_safely(): void
    {
        // The "cut-out" is only key colour: no product found.
        $this->fakeOpenAI($this->analysis(), null, function (Request $r) {
            preg_match('/#([0-9A-F]{6})/', (string) $r->body(), $m);
            [$red, $green, $blue] = sscanf($m[1] ?? 'FF00FF', '%02x%02x%02x');
            $img = imagecreatetruecolor(400, 300);
            imagefill($img, 0, 0, imagecolorallocate($img, $red, $green, $blue));
            ob_start();
            imagepng($img);

            return Http::response(['data' => [['b64_json' => base64_encode(ob_get_clean())]], 'usage' => []]);
        });
        $batch = $this->batch(['background' => 'neutral']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value);
        // The paid retouch is kept, on the photo's own background, with a note.
        $this->assertSame('edited', $image->ai_status);
        $this->assertStringEndsWith('.jpg', $image->ai_path);
        Storage::disk('local')->assertExists($image->ai_path);
        $this->assertCount(1, Storage::disk('local')->files($batch->storageDirectory().'/ai'));
        $this->assertContains('ai_background_failed', array_column($image->warnings, 'code'));
        $this->assertSame(2, $this->sentTo('/images/edits'), 'retouch + cut-out, nothing paid twice');
    }

    public function test_a_failing_check_after_a_paid_edit_does_not_pay_again_on_retry(): void
    {
        $checks = 0;
        Http::fake(function (Request $request) use (&$checks) {
            if (str_ends_with($request->url(), '/responses')) {
                if (($request['text']['format']['name'] ?? '') === 'edit_verification' && ++$checks <= 3) {
                    return Http::response(['error' => ['message' => 'Server error', 'type' => 'server_error']], 500);
                }

                return Http::response($this->responsesBody(($request['text']['format']['name'] ?? '') === 'edit_verification' ? $this->verification() : $this->analysis()));
            }

            return Http::response($this->editBody($request));
        });
        $batch = $this->batch(['strength' => 'normal']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value);
        $this->assertSame('edited', $image->ai_status);
        $this->assertSame(1, $this->sentTo('/images/edits'), 'the retry only redid the check');
        $this->assertSame(4, $checks);
        $this->assertTrue($image->analysis['ai_edit']['verified']);
        Storage::disk('local')->assertExists($image->ai_path);
        $this->assertCount(1, Storage::disk('local')->files($batch->storageDirectory().'/ai'));
    }

    public function test_a_check_that_keeps_failing_keeps_the_edit_marked_unverified(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/responses')) {
                return ($request['text']['format']['name'] ?? '') === 'edit_verification'
                    ? Http::response(['error' => ['message' => 'Server error', 'type' => 'server_error']], 500)
                    : Http::response($this->responsesBody($this->analysis()));
            }

            return Http::response($this->editBody($request));
        });
        $batch = $this->batch(['strength' => 'normal']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value);
        $this->assertSame('edited', $image->ai_status, 'never rejected: the paid edit is used');
        $this->assertSame(1, $this->sentTo('/images/edits'));
        $this->assertSame('failed', $image->analysis['ai_edit']['verified']);
        $this->assertContains('ai_edit_unverified', array_column($image->warnings, 'code'));
        Storage::disk('local')->assertExists($image->ai_path);
    }

    public function test_a_temporary_cutout_failure_reuses_the_stored_retouch_on_retry(): void
    {
        $cutouts = 0;
        $this->fakeOpenAI($this->analysis(), null, function (Request $r) use (&$cutouts) {
            if (str_contains((string) $r->body(), 'Cut out the product') && ++$cutouts === 1) {
                return Http::response(['error' => ['message' => 'Server error', 'type' => 'server_error']], 500);
            }

            return Http::response($this->editBody($r));
        });
        $batch = $this->batch(['strength' => 'subtle', 'background' => 'neutral']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('edited', $image->ai_status);
        $retouches = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/images/edits') && ! str_contains((string) $r->body(), 'Cut out the product'));
        $this->assertCount(1, $retouches, 'the retouch was paid once');
        $this->assertSame(2, $cutouts);
        $this->assertNotContains('ai_background_failed', array_column($image->warnings ?? [], 'code'));
        // Only the final composite is left: the intermediate retouch is removed once the photo is done.
        $this->assertSame([$image->ai_path], Storage::disk('local')->files($batch->storageDirectory().'/ai'));
        $this->assertArrayNotHasKey('retouch', $image->analysis['ai_edit'] ?? []);
    }

    public function test_a_refused_cutout_keeps_the_retouch(): void
    {
        $this->fakeOpenAI($this->analysis(), null, fn (Request $r) => str_contains((string) $r->body(), 'Cut out the product')
            ? Http::response(['error' => ['message' => 'Refused', 'type' => 'invalid_request_error', 'code' => 'moderation_blocked']], 400)
            : Http::response($this->editBody($r)));
        $batch = $this->batch(['strength' => 'subtle', 'background' => 'neutral']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('edited', $image->ai_status);
        $this->assertContains('ai_background_failed', array_column($image->warnings, 'code'));
        $this->assertSame(2, $this->sentTo('/images/edits'));
        Storage::disk('local')->assertExists($image->ai_path);
    }

    public function test_input_fidelity_is_dropped_when_the_model_refuses_it(): void
    {
        $this->fakeOpenAI($this->analysis(), null, function (Request $request) {
            return str_contains((string) $request->body(), 'input_fidelity')
                ? Http::response(['error' => ['message' => "Unknown parameter: 'input_fidelity'.", 'type' => 'invalid_request_error']], 400)
                : Http::response($this->editBody($request));
        });
        $batch = $this->batch(['strength' => 'strong']);

        $this->artisan('bora:work')->assertSuccessful();

        $this->assertSame('edited', $batch->images()->first()->ai_status);
        // The retouch: refused once with input_fidelity, then sent without.
        $this->assertSame(2, $this->sentTo('/images/edits'));
    }

    public function test_background_option_requires_an_edit_even_when_subtle(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['strength' => 'subtle', 'background' => 'neutral']);

        $this->artisan('bora:work')->assertSuccessful();

        $this->assertSame(2, $this->sentTo('/images/edits'), 'retouch + cut-out');
        $this->assertSame('edited', $batch->images()->first()->ai_status);
    }

    public function test_remove_people_without_people_needs_no_extra_edit(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['strength' => 'subtle', 'remove_people' => true, 'background' => 'blur_light']);

        $this->artisan('bora:work')->assertSuccessful();

        // Retouch + cut-out, but no AI background for people that are not there.
        $this->assertSame(2, $this->sentTo('/images/edits'));
    }

    public function test_person_in_front_of_product_gives_a_warning(): void
    {
        $this->fakeOpenAI(
            $this->analysis(['people' => ['present' => true, 'count' => 1, 'overlaps_product' => true]]),
            $this->verification(peopleVisible: true),
        );
        $batch = $this->batch(['remove_people' => true]);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $codes = array_column($image->warnings, 'code');
        $this->assertContains('person_overlaps_product', $codes);
        $this->assertContains('people_not_removed', $codes);
        $this->assertStringContainsString('verwijder personen die geen deel van de auto of het product zijn', (string) Http::recorded(fn ($r) => str_ends_with($r->url(), '/images/edits'))->last()[0]->body());
    }

    public function test_unrestorable_photo_gets_warning_and_a_careful_retouch(): void
    {
        $this->fakeOpenAI($this->analysis(['restorable' => false, 'severity' => 'major', 'issues' => ['motion_blur' => true], 'needs_generative_edit' => true]));
        $batch = $this->batch(['strength' => 'normal']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame(1, $this->sentTo('/images/edits'));
        $this->assertStringContainsString('verzin geen details', (string) Http::recorded(fn ($r) => str_ends_with($r->url(), '/images/edits'))->first()[0]->body());
        $this->assertContains('motion_blur', array_column($image->warnings, 'code'));

        $this->getJson("/api/company/batches/{$batch->id}")
            ->assertJsonFragment(['message' => 'Deze foto is sterk bewogen. Verbetering is beperkt mogelijk.']);
    }

    public function test_rate_limit_is_retried_within_the_request(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            return ++$calls === 1
                ? Http::response(['error' => ['message' => 'Rate limit', 'type' => 'requests', 'code' => 'rate_limit_exceeded']], 429)
                : Http::response($this->responsesBody($this->analysis()));
        });
        config(['services.openai.edit_policy' => 'never']); // analysis only
        $batch = $this->batch(['strength' => 'subtle']);

        $this->artisan('bora:work')->assertSuccessful();

        $this->assertSame(2, $calls);
        $this->assertSame('analyzed', $batch->images()->first()->ai_status);
        Sleep::assertSleptTimes(1);
    }

    /** A washed-out photo (only light tones), as a local check would flag it. */
    private function washedOutBatch(): Batch
    {
        $id = $this->actingAs($this->user)->postJson('/api/company/batches', ['name' => 'Wit'])->json('data.id');
        $img = imagecreatetruecolor(400, 300);
        for ($y = 0; $y < 300; $y++) {
            $v = 205 + (int) (50 * $y / 299);
            imageline($img, 0, $y, 399, $y, imagecolorallocate($img, $v, $v, $v));
        }
        ob_start();
        imagejpeg($img, null, 90);
        $upload = $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->createWithContent('wit.jpg', ob_get_clean())])->assertCreated();

        // With AI configured the simple local check shows nothing yet: the AI judges the photo.
        $this->assertSame([], $upload->json('data.warnings'));
        $this->postJson("/api/company/batches/{$id}/start")->assertOk();

        return Batch::query()->findOrFail($id);
    }

    public function test_ai_judgement_replaces_the_local_exposure_check(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->washedOutBatch();

        $this->artisan('bora:work')->assertSuccessful();

        $this->assertNotContains('too_bright', array_column($batch->images()->first()->warnings, 'code'));
    }

    public function test_without_ai_answer_the_local_exposure_check_is_shown(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Server error', 'type' => 'server_error']], 500)]);
        $batch = $this->washedOutBatch();

        $this->artisan('bora:work')->assertSuccessful();

        $codes = array_column($batch->images()->first()->warnings, 'code');
        $this->assertContains('ai_unavailable', $codes);
        $this->assertContains('too_bright', $codes);
    }

    public function test_openai_down_retries_the_job_then_falls_back_to_local(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Server error', 'type' => 'server_error']], 500)]);
        $batch = $this->batch();

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value, 'photo is never stuck because of OpenAI');
        $this->assertSame('fallback', $image->ai_status);
        $this->assertContains('ai_unavailable', array_column($image->warnings, 'code'));
        // 3 job attempts x (1 + 2 in-request retries)
        $this->assertSame(9, $this->sentTo('/responses'));
        $this->assertSame('completed', $batch->fresh()->status->value);
    }

    public function test_insufficient_quota_falls_back_immediately(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 429)]);
        $batch = $this->batch();

        $this->artisan('bora:work')->assertSuccessful();

        $this->assertSame(1, $this->sentTo('/responses'));
        $this->assertSame('fallback', $batch->images()->first()->ai_status);
    }

    public function test_analysis_is_reused_and_not_sent_twice(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['strength' => 'subtle']);
        $this->artisan('bora:work')->assertSuccessful();

        // Process again (as re-optimise will do in phase 6).
        $image = $batch->images()->first();
        $image->forceFill(['status' => 'queued'])->save();
        app(ImagePipeline::class)->process($image);

        // One analysis and one verification: nothing is sent again.
        $this->assertSame(2, $this->sentTo('/responses'));
        $this->assertSame(1, $this->sentTo('/images/edits'));
    }

    public function test_ai_switched_off_by_super_admin_sends_nothing(): void
    {
        Http::fake();
        app(SystemSettings::class)->update(['ai_enabled' => false]);
        $batch = $this->batch(['strength' => 'strong']);

        $this->artisan('bora:work')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('skipped', $batch->images()->first()->ai_status);
        $this->assertSame('completed', $batch->fresh()->status->value);
    }

    public function test_edit_size_follows_the_photo_ratio_in_multiples_of_16(): void
    {
        $service = app(ImageEditService::class);
        config(['services.openai.max_side' => 2048]);

        $this->assertSame('2048x1536', $service->size(3072, 2304));
        $this->assertSame('1152x2048', $service->size(1728, 3072));
        $this->assertSame('1200x800', $service->size(1200, 800));

        config(['services.openai.size_strategy' => 'auto']);
        $this->assertSame('auto', $service->size(3072, 2304));
    }

    public function test_prompt_always_contains_all_integrity_rules(): void
    {
        $prompt = app(EditInstructionBuilder::class)->build($this->analysis(), BatchSettings::fromArray(['background' => 'remove']), false);

        foreach (EditInstructionBuilder::INTEGRITY_RULES as $rule) {
            $this->assertStringContainsString($rule, $prompt);
        }
        $this->assertStringContainsString('pure white', $prompt);
        $this->assertStringContainsString('never alter any pixel of the product', $prompt);
    }

    public function test_api_key_is_never_exposed_to_the_frontend(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->getJson('/api/admin/settings')->assertDontSee('sk-test-secret-key');
        $this->getJson('/api/meta')->assertDontSee('sk-test-secret-key');
    }

    public function test_reoptimize_reuses_the_analysis_and_makes_a_new_edit(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['strength' => 'subtle']);
        $this->artisan('bora:work')->assertSuccessful();
        $image = $batch->images()->first();
        $this->assertSame(1, $this->sentTo('/images/edits'));

        $this->postJson("/api/company/images/{$image->id}/reoptimize", ['strength' => 'subtle', 'background' => 'neutral', 'remove_people' => false])
            ->assertStatus(202);
        $this->artisan('bora:work')->assertSuccessful();

        $image->refresh();
        $this->assertSame('edited', $image->ai_status);
        $this->assertSame(3, $this->sentTo('/images/edits'), 'retouch, then retouch + cut-out');
        // The old edit and the intermediate retouch are gone: only the new result is stored.
        $this->assertSame([$image->ai_path], Storage::disk('local')->files($batch->storageDirectory().'/ai'));
        // analysis once + a verification per result; no second analysis
        $this->assertSame(3, $this->sentTo('/responses'));
        $this->assertSame(2, ImageProcessingRecord::query()->where('type', 'reoptimize')->count());
    }

    public function test_changed_text_is_restored_from_the_original_instead_of_rejecting(): void
    {
        // The retouch "rewrites" a block of text on the product (dark rectangle); the check points at it.
        $verification = array_replace($this->verification(), [
            'text_or_logos_altered' => true, 'notes' => 'screen text differs',
            'reason_nl' => 'de tekst op het scherm is veranderd', 'reason_en' => 'the text on the screen changed',
            'regions' => [['kind' => 'display', 'x' => 225 / 512, 'y' => 175 / 384, 'width' => 50 / 512, 'height' => 25 / 384]],
        ]);
        $this->fakeOpenAI($this->analysis(), $verification, function (Request $r) {
            $img = imagecreatetruecolor(512, 384);
            imagefill($img, 0, 0, imagecolorallocate($img, 150, 160, 170));
            imagefilledrectangle($img, 128, 96, 384, 288, imagecolorallocate($img, 200, 30, 30));
            imagefilledrectangle($img, 225, 175, 275, 200, imagecolorallocate($img, 20, 20, 20));
            ob_start();
            imagepng($img);

            return Http::response(['data' => [['b64_json' => base64_encode(ob_get_clean())]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]);
        });
        $batch = $this->batch(['strength' => 'normal']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value);
        $this->assertSame('edited', $image->ai_status, 'the retouch is kept');
        $this->assertNotNull($image->ai_path);
        $this->assertNotContains('ai_edit_rejected_reason', array_column($image->warnings ?? [], 'code'));
        $this->assertNotContains('ai_edit_differs_reason', array_column($image->warnings ?? [], 'code'));
        $this->assertSame(1, $image->analysis['repair']['restored']);

        // The changed block has the original (red) pixels again; the retouched background stays.
        $out = imagecreatefromstring(Storage::disk('local')->get($image->ai_path));
        $scale = imagesx($out) / 512;
        $c = imagecolorat($out, (int) (250 * $scale), (int) (187 * $scale));
        $this->assertGreaterThan(150, ($c >> 16) & 0xFF);
        $this->assertLessThan(80, ($c >> 8) & 0xFF);
        $bg = imagecolorat($out, 10, 10);
        $this->assertEqualsWithDelta(150, ($bg >> 16) & 0xFF, 12);
    }

    public function test_a_change_that_cannot_be_located_keeps_the_edit_with_a_note(): void
    {
        $this->fakeOpenAI($this->analysis(), $this->verification(ok: false));
        $batch = $this->batch(['strength' => 'strong']);

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('edited', $image->ai_status);
        $this->assertNotNull($image->ai_path);
        $this->assertContains('ai_edit_differs_reason', array_column($image->warnings, 'code'));
        $this->assertStringContainsString('de lakkleur is veranderd', collect($this->getJson("/api/company/batches/{$image->batch_id}")->json('data.images.0.warnings'))->pluck('message')->implode(' '));
    }

    public function test_small_ai_result_still_reaches_the_chosen_resolution_but_never_beyond_the_photo(): void
    {
        $this->fakeOpenAI($this->analysis());
        // Photo of 1600 px; the AI answers at 512 px (OPENAI_MAX_SIDE in tests).
        $batch = $this->batch(['strength' => 'strong', 'resolution' => '2000'], width: 1600);
        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('edited', $image->ai_status);
        // 2000 asked, but the photo itself only has 1600 px: enlarged to 1600, not to 2000.
        $this->assertSame(1600, max($image->output_width, $image->output_height));

        $batch = $this->batch(['strength' => 'strong', 'resolution' => '1600', 'aspect_ratio' => '1:1'], width: 1600);
        $this->artisan('bora:work')->assertSuccessful();
        $image = $batch->images()->first();
        // Square crop of a 1600 x 1200 photo: at most 1200 px.
        $this->assertSame([1200, 1200], [$image->output_width, $image->output_height]);
    }

    public function test_unnamed_batch_is_named_from_the_analysis_and_files_follow(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['name' => null], photos: 2);
        $this->assertNull($batch->name);

        $this->artisan('bora:work')->assertSuccessful();

        $batch->refresh();
        $this->assertSame('Volkswagen Golf 8', $batch->name);
        $this->assertTrue($batch->auto_named);
        $this->assertSame(['volkswagen-golf-8-01.jpg', 'volkswagen-golf-8-02.jpg'], $batch->images()->orderBy('position')->pluck('output_filename')->all());
        $this->getJson("/api/company/batches/{$batch->id}")->assertJsonPath('data.auto_named', true);
    }

    public function test_a_typed_name_is_never_replaced(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['name' => 'Mijn auto']);
        $this->artisan('bora:work')->assertSuccessful();

        $batch->refresh();
        $this->assertSame('Mijn auto', $batch->name);
        $this->assertFalse($batch->auto_named);
        $this->assertSame('mijn-auto-01.jpg', $batch->images()->first()->output_filename);
    }

    public function test_hourly_trim_keeps_only_what_is_still_needed(): void
    {
        $this->fakeOpenAI($this->analysis());
        $batch = $this->batch(['strength' => 'strong'], photos: 2);
        $this->artisan('bora:work')->assertSuccessful();
        $this->get("/api/company/batches/{$batch->id}/download")->assertOk();

        $image = $batch->images()->orderBy('position')->first();
        [$original, $ai, $zip] = [$image->original_path, $image->ai_path, $batch->fresh()->zip_path];
        $this->assertNotNull($ai);
        $before = $batch->fresh()->storage_bytes;

        // Within the hour nothing is touched (a rerun must still find the AI result).
        $this->artisan('bora:trim-storage')->assertSuccessful();
        $this->assertNotNull($image->fresh()->ai_path);

        $this->travel(7)->hours();
        $this->artisan('bora:trim-storage')->assertSuccessful();

        $image->refresh();
        $disk = Storage::disk('local');
        $this->assertNull($image->original_path);
        $this->assertNull($image->ai_path);
        $disk->assertMissing($original);
        $disk->assertMissing($ai);
        $disk->assertMissing($zip);
        $disk->assertExists($image->working_path);
        $this->assertLessThan($before, $batch->fresh()->storage_bytes);

        // Before/after, single download and ZIP still work.
        $this->get("/api/company/images/{$image->id}/working")->assertOk();
        $this->get("/api/company/images/{$image->id}/download")->assertOk();
        $this->get("/api/company/batches/{$batch->id}/download")->assertOk();
    }
}
