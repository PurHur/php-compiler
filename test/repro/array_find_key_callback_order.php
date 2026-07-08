<?php

declare(strict_types=1);

$a = [1 => 'a', 2 => 'b'];
$findKeyHit = array_find_key($a, fn ($k, $v) => $k === 2);
$findKeyMiss = array_find_key([1 => 'a'], fn ($k, $v) => $k > 5);
$anyKeyHit = array_any_key($a, fn ($k, $v) => $k === 2);

echo 'find_key_hit=', var_export($findKeyHit, true), "\n";
echo 'find_key_miss=', var_export($findKeyMiss, true), "\n";
echo 'any_key_hit=', $anyKeyHit ? 'true' : 'false', "\n";
