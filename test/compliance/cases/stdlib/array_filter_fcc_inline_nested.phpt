--TEST--
stdlib array_filter() FCC callback + inline nested haystack (#15490, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$r = array_filter(str_split(str_repeat('a1', 1)), is_numeric(...));
if ($r !== [1 => '1']) {
    echo 'fail inline fcc';
    exit(1);
}

$h = str_split('a1');
if (array_filter($h, is_numeric(...)) !== [1 => '1']) {
    echo 'fail variable fcc';
    exit(1);
}

echo "ok\n";
?>
--EXPECT--
ok
