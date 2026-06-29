<?php

declare(strict_types=1);

/**
 * Issue #11419 — filter_list()/filter_id()/filter_var() sanitization registry (ext/filter/filter.c).
 */

$list = filter_list();
$ok = count($list) === 21
    && in_array('string', $list, true)
    && filter_id('string') === FILTER_SANITIZE_STRING
    && filter_var('123abc', FILTER_SANITIZE_NUMBER_INT) === '123';

echo 'filter_list_sanitize_ok=', $ok ? '1' : '0', "\n";
