--TEST--
Language: array callable ['Class','method']() (#32299, Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL)
--FILE--
<?php
class C
{
    public static function m()
    {
        echo 'U';
    }
}
$cb = ['C', 'm'];
$cb();
echo '|';
$s = 'C::m';
$s();
echo '|';
(new C)->m();
--EXPECT--
U|U|U
