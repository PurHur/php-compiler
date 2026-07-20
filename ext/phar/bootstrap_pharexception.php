<?php

declare(strict_types=1);

/**
 * Global PharException when ext/phar is not loaded on the host (php-src ext/phar/phar_object.c).
 */
if (!\class_exists(\PharException::class, false)) {
    class PharException extends \Exception
    {
    }
}
