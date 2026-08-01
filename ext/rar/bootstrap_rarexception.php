<?php

declare(strict_types=1);

/**
 * Global RarException when pecl-rar is not loaded on the host (PECL rar; #6237).
 */
if (!\class_exists(\RarException::class, false)) {
    class RarException extends \Exception
    {
    }
}
