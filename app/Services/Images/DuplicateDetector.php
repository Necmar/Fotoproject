<?php

namespace App\Services\Images;

use App\Models\Batch;
use App\Models\Image;

/**
 * Marks identical and near-identical photos inside one batch. Nothing is
 * removed: the later photo gets a warning ("lijkt sterk op foto 7") and the
 * user decides which one to keep.
 */
class DuplicateDetector
{
    public function __construct(private readonly ImagePreparer $preparer) {}

    public function markBatch(Batch $batch): int
    {
        $threshold = (int) config('bora.processing.duplicate_threshold', 6);
        $images = $batch->images()->get();
        $seen = [];
        $marked = 0;

        foreach ($images as $image) {
            /** @var Image|null $match */
            $match = null;

            foreach ($seen as $earlier) {
                $exact = $image->content_hash && $image->content_hash === $earlier->content_hash;
                $similar = $image->perceptual_hash && $earlier->perceptual_hash
                    && PerceptualHash::distance($image->perceptual_hash, $earlier->perceptual_hash) <= $threshold;

                if ($exact || $similar) {
                    $match = $earlier;
                    break;
                }
            }

            $warnings = $match
                ? [['code' => $match->content_hash === $image->content_hash ? 'duplicate_exact' : 'duplicate_similar', 'params' => ['position' => $match->position]]]
                : [];

            $image->forceFill([
                'duplicate_of_id' => $match?->id,
                'warnings' => $this->preparer->mergeWarnings($image->warnings ?? [], 'duplicate', $warnings),
            ])->save();

            $marked += $match ? 1 : 0;
            $seen[] = $image;
        }

        return $marked;
    }
}
