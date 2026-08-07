--TEST--
GMP ReflectionClass::isFinal() under PROFILE≥8.4 (php-src ext/gmp/gmp.stub.php; #28135)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo (new ReflectionClass(GMP::class))->isFinal() ? "gmp_final_yes\n" : "gmp_final_no\n";
?>
--EXPECT--
gmp_final_yes
