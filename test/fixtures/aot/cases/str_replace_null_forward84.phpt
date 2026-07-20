--TEST--
AOT: str_replace null subject soft-null on 8.4 (#21198, re-#19241)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo str_replace('a', 'b', null) === '' ? 'ok' : 'bad', "\n";
?>
--EXPECT--
ok
