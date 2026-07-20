--TEST--
AOT: password_verify(null) soft-null on 8.4 forward (#21314)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo password_verify(null, 'x') ? "true\n" : "false\n";
?>
--EXPECT--
false
