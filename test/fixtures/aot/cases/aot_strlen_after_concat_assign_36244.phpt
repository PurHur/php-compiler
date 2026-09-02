--TEST--
Language: AOT strlen() must not fold on stale init compileTimeString after `.=` (#36244)
--FILE--
<?php
$s = '';
for ($i = 0; $i < 5; $i++) {
    $s .= 'x';
}
echo $s, '|', strlen($s), "\n";

$u = 'a';
for ($i = 0; $i < 5; $i++) {
    $u = $u . 'x';
}
echo $u, '|', strlen($u), "\n";

$t = '';
$t .= 'ab';
$t .= 'cd';
echo $t, '|', strlen($t), "\n";

$u = 'a';
for ($i = 0; $i < 5; $i++) {
    $u = $u . 'x';
}
echo $u, '|', strlen($u), "\n";
--EXPECT--
xxxxx|5
axxxxx|6
abcd|4
axxxxx|6
