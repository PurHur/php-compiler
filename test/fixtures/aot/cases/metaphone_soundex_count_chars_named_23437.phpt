--TEST--
AOT: metaphone/count_chars named Zend stub params (#23437)
--FILE--
<?php
echo metaphone(string: 'programming', max_phonemes: 4), "\n";
echo count_chars(string: 'a', mode: 3), "\n";
--EXPECT--
PRKR
a
