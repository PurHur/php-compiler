--TEST--
Language: object-expression instance method first-class callable (expr)->m(...) (#10082, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function m(): string {
        return 'hi';
    }
}

$c = (new C())->m(...);
var_export($c());
echo "\n";

$obj = new C();
$f = $obj->m(...);
var_export($f());
echo "\n";
--EXPECT--
'hi'
'hi'
