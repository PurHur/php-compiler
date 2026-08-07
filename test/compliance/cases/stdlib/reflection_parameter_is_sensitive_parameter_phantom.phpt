--TEST--
ReflectionParameter::isSensitiveParameter() phantom withheld on all profiles (#28528, re-#16130)
--FILE--
<?php
declare(strict_types=1);

echo method_exists(ReflectionParameter::class, 'isSensitiveParameter') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
