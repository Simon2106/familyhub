<?php

namespace App\Services\Speech;

use RuntimeException;

/**
 * Something went wrong turning speech into words, or words into speech.
 *
 * Carries a sentence fit to put on a wall in a kitchen: the display has no
 * console, no scrollback and nobody standing at it who wants a status code.
 */
class SpeechException extends RuntimeException {}
