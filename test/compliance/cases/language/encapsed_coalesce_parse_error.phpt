--TEST--
Language: encapsed null coalesce in double-quoted strings must parse-error (#14032)
--FILE--
<?php

declare(strict_types=1);

$a = ['b' => 1];
echo "{$a['b'] ?? 0}";
--EXPECT_EXIT--
255
