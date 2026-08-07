--TEST--
Language: #28523 issue-body — isFinal=1 + WRITE_OK under PROFILE=8.4 (Zend inheritance-only final)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { public final int $x = 1; }
$r = new ReflectionProperty(A::class, "x");
echo "isFinal=", $r->isFinal() ? "1" : "0", "\n";
$a = new A();
try { $a->x = 9; echo "WRITE_OK\n"; } catch (Throwable $e) { echo "WRITE_ERR\n"; }
--EXPECT--
isFinal=1
WRITE_OK
