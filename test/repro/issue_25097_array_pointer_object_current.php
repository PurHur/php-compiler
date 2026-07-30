<?php

declare(strict_types=1);

/**
 * Issue #25097 — cast stdClass next/current must survive by-ref call-arg release.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$o2 = (object)['a' => 1, 'b' => 2];
next($o2);
echo 'next_current=' . var_export(current($o2), true) . ' key=' . var_export(key($o2), true) . "\n";

$o = (object)['a' => 1, 'b' => 2, 'c' => 3];
end($o);
$r = prev($o);
echo 'prev_ret=' . var_export($r, true)
   . ' current=' . var_export(current($o), true)
   . ' key=' . var_export(key($o), true) . "\n";
