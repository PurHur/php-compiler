--TEST--
NoRewindIterator::valid() true at initial position (php-src ext/spl/spl_iterators.c, #15150)
--FILE--
<?php
$wrap = new NoRewindIterator(new ArrayIterator(['a', 'b']));
echo $wrap->valid() ? '1' : '0', "\n";
--EXPECT--
1
