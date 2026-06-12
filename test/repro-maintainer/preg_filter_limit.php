<?php

declare(strict_types=1);

// Maintainer repro: preg_filter() $limit JIT/AOT lowering (#4079, ext/pcre/php_pcre.c).
echo preg_filter('/a/', 'X', 'aaa', 2), "\n";

$in = ['baa', 'ccc'];
$out = preg_filter('/a/', 'X', $in, 1);
echo count($out), ':', implode(',', $out), "\n";
