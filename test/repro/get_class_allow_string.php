<?php

declare(strict_types=1);

/**
 * Maintainer repro: get_class()/get_parent_class() optional $allow_string (#17395).
 *
 * Requires PHP_COMPILER_PROFILE=8.4 (forward profile).
 */

function probe(string $label, callable $fn): void
{
    try {
        $result = $fn();
        echo $label, '=', var_export($result, true), "\n";
    } catch (Throwable $e) {
        echo $label, '=', $e::class, ':', $e->getMessage(), "\n";
    }
}

probe('get_class_false', static fn () => get_class(new stdClass(), false));
probe('get_class_true', static fn () => get_class(new stdClass(), true));
probe('get_parent_false', static fn () => get_parent_class(new stdClass(), false));
probe('get_parent_true', static fn () => get_parent_class(new stdClass(), true));
probe('get_class_string_true', static fn () => get_class('stdClass', true));
