--TEST--
Reflection: isAnonymousClass() phantom on PROFILE≥8.4 — Zend has ReflectionClass::isAnonymous only (#28616)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('isAnonymousClass') ? "fn=1\n" : "fn=0\n";
echo class_exists('ReflectionClass') ? "rc=1\n" : "rc=0\n";
?>
--EXPECT--
fn=0
rc=1
