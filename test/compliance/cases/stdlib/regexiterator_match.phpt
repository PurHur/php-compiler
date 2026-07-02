--TEST--
RegexIterator stores inner iterator and rewind/current work (php-src ext/spl/spl_iterators.c, #15152)
--FILE--
<?php
$it = new RegexIterator(new ArrayIterator(['a1', 'b2', 'c3']), '/\d/');
$it->rewind();
echo $it->current(), "\n";
$seen = [];
foreach ($it as $value) {
    $seen[] = $value;
}
echo implode(',', $seen), "\n";
--EXPECT--
a1
a1,b2,c3
