--TEST--
stdlib array_all()/array_any() inline [] literal vacuous results; array_find() empty NULL (#10827, #12519, #19118, ext/standard/array.c)
--FILE--
<?php
echo array_all([], fn ($v) => (bool) $v) ? 'all' : 'notall', "\n";
echo array_any([], fn ($v) => (bool) $v) ? 'any' : 'notany', "\n";
echo array_find([], fn ($v) => (bool) $v) === null ? 'null' : 'bad', "\n";
--EXPECT--
all
notany
null
