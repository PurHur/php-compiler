--TEST--
Language: valid nested container write via foreach by-reference (#6325)
--FILE--
<?php
$a = [0 => [1]];
foreach ($a as &$v) {
    $v[0] = 2;
}
echo $a[0][0], "\n";
--EXPECT--
2
