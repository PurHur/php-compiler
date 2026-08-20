--TEST--
AOT: str_word_count() named string:/format:/characters: (#23920)
--FILE--
<?php
echo str_word_count(string: 'a-b', format: 0, characters: '-'), "\n";
echo str_word_count('a-b', 0, '-'), "\n";
--EXPECT--
1
1
