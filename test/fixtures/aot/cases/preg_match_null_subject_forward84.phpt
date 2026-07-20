--TEST--
AOT: preg_match null subject soft-null on 8.4 (#21198)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo preg_match('/a/', null) === 0 ? 'ok' : 'bad', "\n";
?>
--EXPECT--
ok
