--TEST--
language __PHP_Incomplete_Class property warning includes function-name prefix (#29025, zend_object_handlers.c)
--FILE--
<?php
$o = unserialize('O:1:"X":1:{s:1:"a";i:1;}');
@$o->a;
$e = error_get_last();
echo (str_starts_with($e['message'] ?? '', 'main(): The script tried to access a property on an incomplete object.')
    ? "main_ok\n" : "main_bad\n");

function f($o) {
    @$o->a;
}
f($o);
$e = error_get_last();
echo (str_starts_with($e['message'] ?? '', 'f(): The script tried to access a property on an incomplete object.')
    ? "f_ok\n" : "f_bad\n");

class C {
    public function m($o) {
        @$o->a;
    }
    public static function s($o) {
        @$o->a;
    }
}
(new C)->m($o);
$e = error_get_last();
echo (str_starts_with($e['message'] ?? '', 'C::m(): The script tried to access a property on an incomplete object.')
    ? "m_ok\n" : "m_bad\n");
C::s($o);
$e = error_get_last();
echo (str_starts_with($e['message'] ?? '', 'C::s(): The script tried to access a property on an incomplete object.')
    ? "s_ok\n" : "s_bad\n");

$fn = function ($o) {
    @$o->a;
};
$fn($o);
$e = error_get_last();
echo (str_starts_with($e['message'] ?? '', '{closure}(): The script tried to access a property on an incomplete object.')
    ? "cl_ok\n" : "cl_bad\n");

@property_exists($o, 'a');
$e = error_get_last();
echo (str_starts_with($e['message'] ?? '', 'property_exists(): The script tried to access a property on an incomplete object.')
    ? "pe_ok\n" : "pe_bad\n");
--EXPECT--
main_ok
f_ok
m_ok
s_ok
cl_ok
pe_ok
