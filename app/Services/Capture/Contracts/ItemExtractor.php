<?php

namespace App\Services\Capture\Contracts;

use App\Models\Capture;
use App\Services\Capture\ExtractionResult;

/**
 * Turns a capture into candidate items.
 *
 * An interface so tests can run the whole capture pipeline without calling the
 * API, and so a different model or provider is a binding change.
 */
interface ItemExtractor
{
    public function extract(Capture $capture): ExtractionResult;
}
