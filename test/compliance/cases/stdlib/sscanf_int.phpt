--TEST--
stdlib sscanf() — integer and string out-args (issue #3190)
--FILE--
<?php
$n = 0;
echo sscanf('42', '%d', $n), ' ', $n, "\n";
$a = 0;
$b = 0;
echo sscanf('10 20', '%d %d', $a, $b), ' ', $a, ' ', $b, "\n";
$word = '';
echo sscanf("  hello world", '%s', $word), ' ', $word, "\n";
--EXPECT--
1 42
2 10 20
1 hello
