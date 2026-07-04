--TEST--
stdlib uksort() string callback + array_keys() strict compare (#16056, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = ['a10' => 1, 'a2' => 2];
uksort($a, 'strnatcmp');
if (['a2', 'a10'] !== array_keys($a)) {
    echo 'fail uksort keys';
    exit(1);
}

$b = [3 => 'z', 1 => 'A', 2 => 'a'];
usort($b, 'strnatcasecmp');
if (['A', 'a', 'z'] !== $b) {
    echo 'fail usort values';
    exit(1);
}

$c = ['x' => 1, 'y' => 2, 'z' => 1];
uasort($c, 'strcmp');
if (['x', 'z', 'y'] !== array_keys($c)) {
    echo 'fail uasort keys';
    exit(1);
}

echo "ok\n";
--EXPECT--
ok
