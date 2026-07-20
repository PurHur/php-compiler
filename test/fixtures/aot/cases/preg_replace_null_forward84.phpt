--TEST--
AOT: preg_replace null subject soft-null on 8.4 (#21198, re-#19241)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Empty pattern matches once in '' → 'x' (Zend php_pcre.c).
echo preg_replace('//', 'x', null) === 'x' ? 'ok' : 'bad', "\n";
?>
--EXPECT--
ok
