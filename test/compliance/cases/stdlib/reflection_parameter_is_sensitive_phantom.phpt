--TEST--
ReflectionParameter::isSensitive() phantom withheld on all profiles (#28528, re-#22899/#7072)
--FILE--
<?php
declare(strict_types=1);

echo method_exists(ReflectionParameter::class, 'isSensitive') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
