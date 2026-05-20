--TEST--
Loop statements: for / while / do-while (JIT)
--FILE--
<?php
$s = 0;
for ($i = 0; $i < 5; $i = $i + 1) {
    $s = $s + $i;
}
echo $s, "\n";

$i = 0;
$s = 0;
while ($i < 5) {
    $s = $s + $i;
    $i = $i + 1;
}
echo $s, "\n";

$i = 0;
do {
    $i = $i + 1;
} while ($i < 3);
echo $i, "\n";
--EXPECT--
10
10
3

