--TEST--
AOT: mb_str_split() UTF-8 literal chunks (#26870)
--FILE--
<?php
echo implode('-', mb_str_split('aéi', 1)), "\n";
$p = mb_str_split('αβγ', 1);
echo count($p), ':', $p[0], $p[1], $p[2], "\n";
echo implode(',', mb_str_split('hello', 2, 'ASCII')), "\n";
--EXPECT--
a-é-i
3:αβγ
he,ll,o
