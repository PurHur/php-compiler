--TEST--
stdlib array_all()/array_any()/array_find() inline [] literal vacuous results (#10827, ext/standard/array.c)
--FILE--
<?php
echo array_all([], fn ($v) => (bool) $v) ? 'all' : 'notall', "\n";
echo array_any([], fn ($v) => (bool) $v) ? 'any' : 'notany', "\n";
echo array_find([], fn ($v) => (bool) $v) === null ? 'null' : 'bad', "\n";
--EXPECT--
all
notany
null
