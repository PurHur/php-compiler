--TEST--
stdlib array_map() FCC callback + inline nested haystack (#15487, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$expected = [1, 2];
$inline = array_map(intval(...), str_split(str_repeat('12', 1)));
if ($inline !== $expected) {
    echo 'fail inline fcc';
    exit(1);
}

$h = str_split('12');
if (array_map(intval(...), $h) !== $expected) {
    echo 'fail variable fcc';
    exit(1);
}

echo "ok\n";
?>
--EXPECT--
ok
