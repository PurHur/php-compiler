--TEST--
ReflectionClass lazy factories absent on PROFILE=8.2 — no newLazyGhost arginfo (#27741)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo 'newLazyGhost=', method_exists(ReflectionClass::class, 'newLazyGhost') ? '1' : '0', "\n";
echo 'newLazyProxy=', method_exists(ReflectionClass::class, 'newLazyProxy') ? '1' : '0', "\n";
--EXPECT--
newLazyGhost=0
newLazyProxy=0
