<?php

declare(strict_types=1);

/**
 * Issue #19118 — array_find()/array_find_key() on empty array return NULL (php-src 8.4).
 */

$find = array_find([], static fn ($v) => true);
$findKey = array_find_key([], static fn ($v) => true);
$all = array_all([], static fn ($v) => (bool) $v);
$any = array_any([], static fn ($v) => (bool) $v);
$match = array_find([1, 2, 3], static fn ($v) => 2 === $v);

echo ($find === null ? 'find_null' : 'find_bad'), "\n";
echo ($findKey === null ? 'key_null' : 'key_bad'), "\n";
echo ($all === true ? 'all_true' : 'all_bad'), "\n";
echo ($any === false ? 'any_false' : 'any_bad'), "\n";
echo ($match === 2 ? 'match_ok' : 'match_bad'), "\n";
