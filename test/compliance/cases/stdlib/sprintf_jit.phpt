--TEST--
stdlib sprintf() JIT
--FILE--
<?php
echo sprintf('page %d of %d', 2, 10), "\n";
echo sprintf('<%s>', 'tag'), "\n";
echo sprintf('rate=%f%%', 3.5), "\n";
--EXPECT--
page 2 of 10
<tag>
rate=3.500000%
