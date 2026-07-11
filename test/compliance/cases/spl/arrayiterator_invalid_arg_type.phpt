--TEST--
SPL ArrayIterator invalid arg TypeError not TYPE_BOOL fatal (#9619, ext/spl/spl_array.c)
--FILE--
<?php
declare(strict_types=1);

$it = new ArrayIterator([1, 2, 3]);
echo 'count=', iterator_count($it), "\n";
foreach ($it as $k => $v) {
    echo "$k=$v\n";
}
$it->rewind();
echo 'current=', $it->current(), "\n";

try {
    new ArrayIterator('not-an-array');
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'typeerror=', (str_contains($e->getMessage(), 'array') ? 'ok' : $e->getMessage()), "\n";
}

echo 'empty=', iterator_count(new ArrayIterator()), "\n";
--EXPECT--
count=3
0=1
1=2
2=3
current=1
typeerror=ok
empty=0
