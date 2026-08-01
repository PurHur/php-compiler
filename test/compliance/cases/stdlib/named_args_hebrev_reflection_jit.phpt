--TEST--
hebrev named string/max_chars_per_line (JIT, issue #23693)
--FILE--
<?php
echo hebrev(string: 'abc'), PHP_EOL;
echo hebrev(string: 'xyz', max_chars_per_line: 0), PHP_EOL;
--EXPECT--
abc
xyz
