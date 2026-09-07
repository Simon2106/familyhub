<?php

namespace App\Exceptions;

use RuntimeException;

/** Home Assistant could not be reached, or refused what we asked. */
class HomeAssistantException extends RuntimeException {}
