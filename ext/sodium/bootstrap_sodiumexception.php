<?php

declare(strict_types=1);

/**
 * Global SodiumException when ext/sodium is not loaded on the host (php-src ext/sodium/sodium.c).
 */
if (!\class_exists(\SodiumException::class, false)) {
    class SodiumException extends \Exception
    {
    }
}
