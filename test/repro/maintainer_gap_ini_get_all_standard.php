<?php

declare(strict_types=1);

$all = ini_get_all(null, true);
echo isset($all['display_errors']) ? "has display_errors\n" : "missing display_errors\n";
var_dump(array_keys($all['display_errors'] ?? []));

$flat = ini_get_all(null, false);
echo is_string($flat['display_errors'] ?? null) ? "flat string\n" : "flat not string\n";

$std = ini_get_all('standard', true);
var_dump($std === false ? 'false' : 'array', is_array($std) ? count($std) : null);

$pcre = ini_get_all('pcre', true);
var_dump($pcre === false ? 'false' : 'array', is_array($pcre) ? count($pcre) : null);

$bad = ini_get_all('no_such_ext_xyz', true);
var_dump($bad);
