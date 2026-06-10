--TEST--
stdlib array_rand() on associative arrays returns keys (#4460)
--FILE--
<?php
$b = array('k1' => 1, 'k2' => 2, 'k3' => 3);
$k = array_rand($b);
$ok = 0;
if (is_string($k)) {
    if (isset($b[$k])) {
        $ok = 1;
    }
}
echo $ok, "\n";
$ks = array_rand($b, 2);
sort($ks);
$ok2 = 1;
if (count($ks) !== 2) {
    $ok2 = 0;
}
if ($ks[0] === $ks[1]) {
    $ok2 = 0;
}
if (!isset($b[$ks[0]])) {
    $ok2 = 0;
}
if (!isset($b[$ks[1]])) {
    $ok2 = 0;
}
if (!is_string($ks[0])) {
    $ok2 = 0;
}
if (!is_string($ks[1])) {
    $ok2 = 0;
}
echo $ok2, "\n";
--EXPECT--
1
1
