<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Super Admin > Systeem > OpenAI > "Verbinding testen". */
class OpenAIConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        config(['services.openai.key' => 'sk-test', 'services.openai.analysis_model' => 'gpt-5.4-mini', 'services.openai.image_model' => 'gpt-image-2']);
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    private function analysisResponse(): array
    {
        $json = json_encode([
            'product' => ['description' => 'red box', 'category' => 'other'],
            'product_box' => ['x' => 0.2, 'y' => 0.2, 'w' => 0.6, 'h' => 0.6],
            'issues' => array_fill_keys(['too_dark', 'too_bright', 'blurry', 'motion_blur', 'noisy', 'poor_white_balance', 'low_contrast', 'low_quality', 'crooked', 'distracting_background', 'harsh_shadows'], false),
            'severity' => 'none', 'restorable' => true,
            'people' => ['present' => false, 'count' => 0, 'overlaps_product' => false],
            'visible' => ['license_plate' => false, 'text_or_logos' => false, 'damage' => false, 'damage_description' => ''],
            'corrections' => array_fill_keys(['exposure', 'contrast', 'warmth', 'tint', 'sharpen', 'denoise', 'rotate_degrees'], 0),
            'needs_generative_edit' => false, 'edit_instructions' => '',
        ]);

        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $json]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    public function test_all_steps_pass_with_a_working_connection(): void
    {
        Http::fake([
            '*/models/*' => Http::response(['id' => 'x']),
            '*/responses' => Http::response($this->analysisResponse()),
        ]);

        $steps = collect($this->postJson('/api/admin/openai/test')->assertOk()->json('data'))->keyBy('step');

        $this->assertTrue($steps['analysis_model']['ok']);
        $this->assertTrue($steps['image_model']['ok']);
        $this->assertTrue($steps['analysis']['ok']);
        $this->assertArrayNotHasKey('edit', $steps->all());
    }

    public function test_openai_error_messages_are_shown_per_step(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'models/gpt-image-2')) {
                return Http::response(['error' => ['message' => 'The model `gpt-image-2` does not exist or you do not have access to it.', 'code' => 'model_not_found']], 404);
            }
            if (str_contains($request->url(), 'images/edits')) {
                return Http::response(['error' => ['message' => 'Your organization must be verified to use the model `gpt-image-2`.', 'type' => 'invalid_request_error']], 403);
            }

            return str_contains($request->url(), 'responses') ? Http::response($this->analysisResponse()) : Http::response(['id' => 'x']);
        });

        $steps = collect($this->postJson('/api/admin/openai/test', ['edit' => true])->assertOk()->json('data'))->keyBy('step');

        $this->assertFalse($steps['image_model']['ok']);
        $this->assertStringContainsString('does not exist', $steps['image_model']['message']);
        $this->assertTrue($steps['analysis']['ok']);
        $this->assertFalse($steps['edit']['ok']);
        $this->assertStringContainsString('must be verified', $steps['edit']['message']);
    }

    public function test_company_owner_cannot_run_the_test(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/admin/openai/test')->assertForbidden();
    }
}
