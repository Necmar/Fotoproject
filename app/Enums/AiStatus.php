<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** What the AI did with an image (images.ai_status). */
enum AiStatus: string
{
    use HasValues;

    /** AI switched off or not configured: local processing only. */
    case Skipped = 'skipped';
    /** Analysed; corrections applied locally, no generative edit needed. */
    case Analyzed = 'analyzed';
    /** Generative edit applied (and verified). */
    case Edited = 'edited';
    /** Edit rejected by the integrity check; local corrections used instead. */
    case EditRejected = 'edit_rejected';
    /** OpenAI unavailable or refused; local corrections used instead. */
    case Fallback = 'fallback';
}
