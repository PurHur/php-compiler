<?php

declare(strict_types=1);

namespace Filter;

/**
 * Thrown when FILTER_THROW_ON_FAILURE rejects a value (php-src ext/filter; PHP 8.5+).
 */
class FilterFailedException extends FilterException
{
}
