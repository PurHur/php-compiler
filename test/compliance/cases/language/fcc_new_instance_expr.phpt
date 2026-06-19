--TEST--
Language: instance method first-class callable on (new C())->m(...) (#10102, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function m(): string {
        return 'hi';
    }

    public static function s(): string {
        return 'static';
    }
}

$c = (new C())->m(...);
var_export($c());
echo "\n";

$static = C::s(...);
var_export($static());
echo "\n";
--EXPECT--
'hi'
'static'
