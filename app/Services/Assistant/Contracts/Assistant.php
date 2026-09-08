<?php

namespace App\Services\Assistant\Contracts;

use App\Models\Household;
use App\Services\Assistant\AssistantAnswer;

/**
 * Answers a question about the household.
 *
 * An interface so the whole page can be exercised in tests without an API
 * call, exactly as the capture pipeline is.
 */
interface Assistant
{
    /**
     * @param  list<array{role: string, content: string}>  $history  earlier
     *                                                               turns, oldest first, so "and the week after?" means something
     */
    public function ask(string $question, Household $household, array $history = []): AssistantAnswer;
}
