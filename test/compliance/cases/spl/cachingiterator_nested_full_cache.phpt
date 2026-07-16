--TEST--
SPL CachingIterator nested new + FULL_CACHE class const (#19769, ext/spl/spl_iterators.c)
--FILE--
<?php
error_reporting(E_ALL);

// Nested new + class-const flags (the #19769 / re-#17400 form).
$it = new CachingIterator(new ArrayIterator(['a', 'b']), CachingIterator::FULL_CACHE);
foreach ($it as $v) {
    echo "v=$v\n";
}
echo "ok\n";

// Locals-bound form must stay green.
$inner = new ArrayIterator(['x', 'y']);
$flags = CachingIterator::FULL_CACHE;
$it2 = new CachingIterator($inner, $flags);
foreach ($it2 as $v) {
    echo "l=$v\n";
}

// Literal flags must stay green.
$it3 = new CachingIterator(new ArrayIterator(['p', 'q']), 256);
foreach ($it3 as $v) {
    echo "n=$v\n";
}
?>
--EXPECT--
v=a
v=b
ok
l=x
l=y
n=p
n=q
