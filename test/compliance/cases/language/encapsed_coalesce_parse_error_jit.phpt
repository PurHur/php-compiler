--TEST--
Language: encapsed null coalesce parse-error — JIT compile-time fatal (#14032)
--FILE--
<?php

declare(strict_types=1);

$a = ['b' => 1];
echo "{$a['b'] ?? 0}";
--EXPECT_EXIT--
255
