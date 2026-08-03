--TEST--
call_user_func_array([Class, method], $args) packs user args only (#27139)
--FILE--
<?php
class CufaClassCallableArgsA {
    public static function who($x, $y = '')
    {
        return static::class . ':' . $x . ':' . $y;
    }
}
class CufaClassCallableArgsB extends CufaClassCallableArgsA {}
echo call_user_func_array([CufaClassCallableArgsA::class, 'who'], ['z', 'w']), "\n";
echo call_user_func_array([CufaClassCallableArgsB::class, 'who'], ['z', 'w']), "\n";
echo call_user_func([CufaClassCallableArgsA::class, 'who'], 'z', 'w'), "\n";
echo call_user_func_array('sprintf', ['%s-%s', 'a', 'b']), "\n";
echo forward_static_call_array([CufaClassCallableArgsB::class, 'who'], ['z']), "\n";
--EXPECT--
CufaClassCallableArgsA:z:w
CufaClassCallableArgsB:z:w
CufaClassCallableArgsA:z:w
a-b
CufaClassCallableArgsB:z:
