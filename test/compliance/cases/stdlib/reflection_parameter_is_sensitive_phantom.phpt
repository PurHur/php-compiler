--TEST--
ReflectionParameter::isSensitive() phantom withheld on 8.2 reference profile (#22899, re-#7072)
--FILE--
<?php
declare(strict_types=1);

echo method_exists(ReflectionParameter::class, 'isSensitive') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
