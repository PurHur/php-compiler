--TEST--
AOT: str_word_count() format 0 (#2382)
--FILE--
<?php
echo str_word_count("one two three"), "\n";
echo str_word_count("fri3nd"), "\n";
--EXPECT--
3
2
