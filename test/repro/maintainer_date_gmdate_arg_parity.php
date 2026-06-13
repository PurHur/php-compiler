<?php

/**
 * Zend vs php-compiler: date()/gmdate() ArgumentCountError/TypeError + format coercion (#4496).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date) / PHP_FUNCTION(gmdate)
 */

class DateFormatStringable
{
    public function __toString(): string
    {
        return 'Y-m-d';
    }
}

function probe(string $label, callable $fn): void
{
    try {
        $r = $fn();
        echo "$label: ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

probe('date_null', static fn () => date(null));
probe('date_stringable', static fn () => date(new DateFormatStringable(), 946684800));
probe('date_bad_ts', static fn () => date('Y-m-d', 'not-an-int'));
probe('date_zero_args', static fn () => date());
probe('date_array_format', static fn () => date([]));

probe('gmdate_null', static fn () => gmdate(null));
probe('gmdate_stringable', static fn () => gmdate(new DateFormatStringable(), 946684800));
probe('gmdate_bad_ts', static fn () => gmdate('Y-m-d', 'not-an-int'));
probe('gmdate_zero_args', static fn () => gmdate());
