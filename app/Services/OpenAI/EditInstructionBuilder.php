<?php

namespace App\Services\OpenAI;

use App\Enums\BackgroundOption;
use App\Enums\OptimizationStrength;
use App\Support\BatchSettings;

/**
 * "Optimalisatie-instructie genereren": builds the edit prompt for one photo
 * from its analysis and the chosen settings. Deterministic PHP (no API call),
 * so the product-integrity rules are always present and always first.
 */
class EditInstructionBuilder
{
    /** Rules that apply to every edit, whatever the settings. */
    public const INTEGRITY_RULES = [
        'Keep the product EXACTLY as it is: same shape, proportions, parts, materials and details.',
        'Keep ALL damage visible exactly as photographed: scratches, dents, cracks, chips, wear, rust, stains, damaged rims. Do not repair, smooth or hide anything.',
        'Keep the exact product colour and finish. Do not recolour, saturate or shift the hue of the product.',
        'Keep all text, numbers, serial numbers, labels, displays and logos on the product pixel-faithful. Do not redraw, sharpen into new shapes, translate or invent characters.',
        'Keep licence plates exactly as they are: same characters, readable, not blurred, not changed.',
        'Do not add objects, parts or accessories to the product. Do not remove parts of the product.',
        'Do not reconstruct hidden parts of the product without enough visual information; leave uncertain areas as they are.',
        'Only improve presentation and image quality: light, exposure, contrast, white balance, colour accuracy, shadows, highlights, sharpness, noise, slight perspective straightening and a cleaner look.',
        'The result must remain a truthful, realistic photograph of this specific product, not an illustration or render.',
        'Keep the camera angle, framing and composition.',
    ];

    /** The "clean advertisement" look (what a good retoucher does), never at the cost of the rules. */
    public const FINISH = 'Rich deep blacks and clean bright highlights, neutral white balance, clear local contrast without haze, '
        .'accurate but vivid colours, low noise and crisp natural sharpness. Remove dust specks, lint, fingerprints and smudges from surfaces '
        .'(these are dirt, not damage), but keep every scratch, dent, crack, stain and sign of wear.';

    public function build(array $analysis, BatchSettings $settings, bool $transparentBackground, bool $conservative = false): string
    {
        $lines = ['Edit this product photograph for an online advertisement.', '', 'STRICT RULES (never break these):'];
        if ($conservative) {
            // Second attempt after an edit that changed the product.
            array_splice($lines, 1, 0, ['A previous edit of this photo changed the product and was rejected. This time make only minimal, global corrections of light and colour, and change the background only as far as the background option below requires. Leave the product itself visually identical to the original.']);
        }

        foreach (self::INTEGRITY_RULES as $i => $rule) {
            $lines[] = ($i + 1).'. '.$rule;
        }

        $lines[] = '';
        $lines[] = 'PRODUCT: '.($analysis['product']['description'] ?: 'the main product in the photo').'.';

        if ($analysis['visible']['damage'] ?? false) {
            $lines[] = 'VISIBLE DAMAGE THAT MUST STAY: '.($analysis['visible']['damage_description'] ?: 'as photographed').'.';
        }
        if ($analysis['visible']['license_plate'] ?? false) {
            $lines[] = 'A licence plate is visible: keep it unchanged and readable.';
        }
        if ($analysis['visible']['text_or_logos'] ?? false) {
            $lines[] = 'Text/logos are visible on the product: keep them exactly as they are.';
        }

        $lines[] = '';
        $lines[] = 'IMAGE QUALITY: '.$this->strengthText($conservative ? OptimizationStrength::Subtle : $settings->strength);

        $problems = array_keys(array_filter($analysis['issues'] ?? []));
        if ($problems) {
            $lines[] = 'Problems to fix where possible: '.str_replace('_', ' ', implode(', ', $problems)).'.';
        }

        if (($analysis['edit_instructions'] ?? '') !== '') {
            $lines[] = 'Specific suggestions (only if they respect the rules): '.$analysis['edit_instructions'];
        }

        $lines[] = '';
        $lines[] = 'BACKGROUND: '.$this->backgroundText($settings->background, $transparentBackground);

        if ($settings->removePeople) {
            $lines[] = 'PEOPLE: remove people who are not part of the product. Fill the freed area with plausible background only. '
                .'If a person covers part of the product, do not invent the hidden product details: keep the covered area as close to the original as possible.';
        } else {
            $lines[] = 'PEOPLE: do not remove or change people.';
        }

        if ($settings->background->editsBackground()) {
            $lines[] = 'Background changes must never alter any pixel of the product itself, including its edges, reflections on the product and its shadow contact.';
        }

        return implode("\n", $lines);
    }

    /**
     * Prompt for the cut-out: product unchanged on a flat key colour. Only the
     * mask is taken from the result; the product pixels come from the original.
     *
     * @param  array{int, int, int}  $rgb
     */
    public function cutout(array $analysis, string $keyName, array $rgb): string
    {
        $hex = sprintf('#%02X%02X%02X', ...$rgb);
        $product = $analysis['product']['description'] ?? '' ?: 'the main product';

        return implode("\n", [
            'Cut out the product in this photograph for a product mask.',
            "PRODUCT: {$product}.",
            "Replace EVERYTHING that is not part of the product with one perfectly flat, uniform colour: pure {$keyName} {$hex}.",
            'That includes the background, floor, walls, sky, people, hands, other objects and every shadow or reflection on the ground.',
            'Keep the complete product, including thin or small parts (mirrors, antennas, wheels, tyres, cables, handles, legs, straps).',
            'Keep the product exactly where it is: same position, size, angle and framing. Do not move, crop, zoom, rotate or re-frame anything.',
            'Keep the product itself unchanged. Do not add outlines, glow, gradients, shadows or colour spill; do not tint the product with the '.$keyName.' colour.',
        ]);
    }

    private function strengthText(OptimizationStrength $strength): string
    {
        return match ($strength) {
            OptimizationStrength::Subtle => 'Light corrections only; stay as close to the original as possible.',
            OptimizationStrength::Normal => 'Professional retouch for a marketplace advertisement: clearly cleaner, crisper and more premium than the original. '.self::FINISH,
            OptimizationStrength::Strong => 'Strong professional retouch for a premium advertisement: noticeably cleaner, crisper and richer than the original, with better light and shadows, while the product stays fully unchanged. '.self::FINISH,
        };
    }

    /**
     * Whole-photo retouch (original background, Normal/Strong): the "clean
     * advertisement" look. Goal first and concrete, so the model really
     * improves the photo; the integrity rules say what must stay true, without
     * forbidding the global tone and colour work that makes a photo look clean.
     */
    public function retouch(array $analysis, BatchSettings $settings): string
    {
        $strong = $settings->strength === OptimizationStrength::Strong;
        $product = ($analysis['product']['description'] ?? '') ?: 'the main subject of the photo';

        $lines = [
            'Retouch this photo into a clean, professional, high-end advertisement photo for an online marketplace listing, '
                .'the way a professional product photographer finishes a photo for a premium brochure. The improvement must be clearly visible.',
            '',
            'DO THIS (whole image):',
            '- Exposure and white balance: correct, neutral and true to life, no colour cast. Recover blown highlights and open up dark shadows where the photo allows.',
            '- Tone: deep, clean blacks; bright, clean highlights; rich midtones. Crisp contrast, no flat or milky areas.',
            '- Clarity: add micro-contrast and definition, remove haze and dullness from the whole photo.',
            '- Sharpness: crisp, well-defined edges and fine detail (stitching, textures, grain of materials); remove noise, grain and compression artefacts.',
            '- Cleaning: remove dust, lint, hairs, crumbs, fingerprints, smudges, water spots, streaks and small stray specks from all surfaces, screens and glass. These are dirt, not defects.',
            '- Colour: clean, vivid and natural, like a premium brochure; not oversaturated, not a filter look.',
            $strong
                ? '- Presentation: go for a striking, polished result with even, flattering light and controlled reflections.'
                : '- Presentation: a polished, attractive result that still looks like a real photo of this exact item.',
            '- Background: '.$this->backgroundText($settings->background, false),
        ];

        if ($settings->removePeople) {
            $lines[] = '- People: remove people who are not part of the item and fill that area with plausible background. If a person covers part of the item, do not invent hidden details.';
        }

        $problems = array_keys(array_filter($analysis['issues'] ?? []));
        if ($problems) {
            $lines[] = '- Problems seen in this photo, fix them: '.str_replace('_', ' ', implode(', ', $problems)).'.';
        }
        if (($analysis['edit_instructions'] ?? '') !== '') {
            $lines[] = '- Notes for this photo: '.$analysis['edit_instructions'];
        }

        array_push($lines, '',
            'KEEP TRUE (the listing must stay honest):',
            "- Subject: {$product}. Same object, shape, proportions, parts and materials; same paint/material colour (brightness and clarity may improve, the hue may not change).",
            '- Every real defect stays visible exactly where it is: scratches, dents, cracks, chips, tears, wear, rust, paint damage, damaged rims.',
            '- Text, numbers, symbols, icons, logos, displays and licence plates stay identical and legible. Never invent, rewrite or blur characters.',
            '- Nothing added to or removed from the item; do not invent parts that are not visible.',
            '- Same camera angle, framing, crop and composition. A real photograph, not a render or illustration.',
        );

        if ($analysis['visible']['damage'] ?? false) {
            $lines[] = '- Damage in this photo that must stay: '.(($analysis['visible']['damage_description'] ?? '') ?: 'as photographed').'.';
        }
        if ($analysis['visible']['license_plate'] ?? false) {
            $lines[] = '- A licence plate is visible: keep it unchanged and readable.';
        }

        return implode("\n", $lines);
    }

    private function backgroundText(BackgroundOption $option, bool $transparent): string
    {
        return match ($option) {
            BackgroundOption::Keep => 'keep the original background and setting; improve and clean it together with the rest of the photo, without adding or removing objects.',
            BackgroundOption::CleanSubtle => 'Keep the original background but subtly tidy it: reduce small litter, stains and visual noise.',
            BackgroundOption::RemoveDistractions => 'Keep the setting but reduce or remove distracting background elements (litter, signs, cables, bins, other vehicles partly in view).',
            BackgroundOption::BlurLight => 'Keep the background but apply a light, natural depth-of-field blur to it; the product stays fully sharp.',
            BackgroundOption::Remove => $transparent
                ? 'Remove the background completely and make it transparent. Keep clean, accurate product edges.'
                : 'Replace the background with plain pure white. Keep clean, accurate product edges and a soft natural contact shadow.',
            BackgroundOption::Neutral => 'Replace the background with a clean, neutral light-grey studio background with soft natural light and a realistic contact shadow.',
        };
    }
}
