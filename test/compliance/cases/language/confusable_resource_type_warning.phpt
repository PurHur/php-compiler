--TEST--
Language: bare resource/integer type Warning (zend_compile confusable types, #26639)
--FILE--
<?php
function f(resource $x) {}
function g(\resource $y) {}
class C {
    public resource $p;
    public function m(): resource { return new stdClass; }
}
echo "ok\n";
--EXPECTF--
Warning: "resource" is not a supported builtin type and will be interpreted as a class name. Write "\resource" to suppress this warning in %s on line %d

Warning: "resource" is not a supported builtin type and will be interpreted as a class name. Write "\resource" to suppress this warning in %s on line %d

Warning: "resource" is not a supported builtin type and will be interpreted as a class name. Write "\resource" to suppress this warning in %s on line %d
ok
