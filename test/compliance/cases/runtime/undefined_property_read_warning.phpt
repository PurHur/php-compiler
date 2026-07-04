--TEST--
Runtime: undefined dynamic property read E_WARNING on stdClass (Zend/zend_object_handlers.c, #15752)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$o = new stdClass;
var_export($o->missing);
echo "\n";
echo isset($o->missing) ? 'isset' : 'not', "\n";
echo property_exists($o, 'missing') ? 'exists' : 'not', "\n";
var_export(@$o->silent);
echo "\n";
class C {
    public int $declared = 1;
}
$c = new C;
var_export($c->missing);
echo "\n";
--EXPECT--
W:Undefined property: stdClass::$missing
NULL
not
not
W:Undefined property: stdClass::$silent
NULL
W:Undefined property: C::$missing
NULL
