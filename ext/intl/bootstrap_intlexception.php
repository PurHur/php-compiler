<?php

declare(strict_types=1);

/**
 * Global IntlException when ext/intl is not loaded on the host (php-src ext/intl/intl_error.c).
 */
if (!\class_exists(\IntlException::class, false)) {
    class IntlException extends \Exception
    {
    }
}
