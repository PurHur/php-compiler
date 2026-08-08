--TEST--
Language: #28956 issue-body — final public string isFinal=1 + write OK under PROFILE=8.4 (Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { final public string $x = "a"; }
$rp = new ReflectionProperty(A::class, "x");
echo "isFinal=", (int) $rp->isFinal(), "\n";
$a = new A();
$a->x = "c";
echo "wrote=", $a->x, "\n";
--EXPECT--
isFinal=1
wrote=c
