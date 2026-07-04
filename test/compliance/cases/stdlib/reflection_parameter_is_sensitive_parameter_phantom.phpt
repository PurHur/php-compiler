--TEST--
ReflectionParameter::isSensitiveParameter() phantom withheld on 8.2 reference profile (#16130, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

echo method_exists(ReflectionParameter::class, 'isSensitiveParameter') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
