--TEST--
stdlib array_rand() — num: named parameter (#10469, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$keys = [1, 2, 3];
$k = array_rand($keys, num: 2);
$ok = 1;
if (!is_array($k)) {
    $ok = 0;
}
if (2 !== count($k)) {
    $ok = 0;
}
if ($k[0] === $k[1]) {
    $ok = 0;
}
foreach ($k as $idx) {
    if (!array_key_exists($idx, $keys)) {
        $ok = 0;
    }
}
echo $ok, "\n";
--EXPECT--
1
