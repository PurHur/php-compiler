--TEST--
stdlib join() alias of implode() (JIT)
--FILE--
<?php
echo join('|', ['one', 'two', 'three']), "\n";
echo join('-', ['4', '5', '6']), "\n";
--EXPECT--
one|two|three
4-5-6
