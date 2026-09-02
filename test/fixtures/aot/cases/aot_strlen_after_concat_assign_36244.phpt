--TEST--
Language: AOT strlen() must not fold on stale init compileTimeString after `.=` (#36244)
--FILE--
<?php
$s = '';
for ($i = 0; $i < 5; $i++) {
    $s .= 'x';
}
echo $s, '|', strlen($s), "\n";

$t = '';
$t .= 'ab';
$t .= 'cd';
echo $t, '|', strlen($t), "\n";
--EXPECT--
xxxxx|5
abcd|4
