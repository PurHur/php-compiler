--TEST--
AOT: list destructuring with spread — [$a, ...$rest] = $arr (#9248)
--FILE--
<?php
$src = [1, 2, 3, 4];
[$a, ...$rest] = $src;
echo $a, ':', $rest[0], ',', $rest[1], ',', $rest[2], "\n";
--EXPECT--
1:2,3,4
