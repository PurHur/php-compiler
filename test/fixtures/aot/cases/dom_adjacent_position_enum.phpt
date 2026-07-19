--TEST--
AOT: Dom\AdjacentPosition enum_exists (#20782)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo enum_exists('Dom\\AdjacentPosition') ? "yes\n" : "no\n";
?>
--EXPECT--
yes
