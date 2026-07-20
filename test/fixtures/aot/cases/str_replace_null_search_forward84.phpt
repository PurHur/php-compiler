--TEST--
AOT: str_replace null $search soft-null on 8.4 (#21189)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo str_replace(null, 'b', 'hay'), "\n";
--EXPECT--
hay
