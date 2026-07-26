--TEST--
wordwrap named cut_long_words argument (JIT, issue #23191)
--FILE--
<?php
var_export(wordwrap(string: 'a b c', width: 2, break: "\n", cut_long_words: true));
echo PHP_EOL;
--EXPECT--
'a
b
c'
