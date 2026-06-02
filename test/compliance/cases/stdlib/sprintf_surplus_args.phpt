--TEST--
stdlib sprintf()/vsprintf() — surplus format arguments ignored (issue #4175, ext/standard/sprintf.c)
--FILE--
<?php
echo vsprintf('%s', ['a', 'b']), "\n";
echo sprintf('%s', 'a', 'b'), "\n";
--EXPECT--
a
a
