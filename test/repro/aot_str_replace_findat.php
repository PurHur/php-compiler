<?php

declare(strict_types=1);

/**
 * #36002 — NestedJIT StrReplaceJitHelper findAt must match under AOT.
 * php-src: ext/standard/string.c php_str_replace
 */
echo str_replace('xy', 'zw', 'xy!'), "\n";
echo str_ireplace('XY', 'zw', 'xy!'), "\n";
$r = ['a' => 'xy', 'b' => 'zw'];
echo str_replace($r['a'], $r['b'], 'xy!'), "\n";
