--TEST--
AOT: call_user_func user-fn + Class::method string (#35100)
--FILE--
<?php
function user_fn()
{
    return 4;
}
function add($a, $b)
{
    return $a + $b;
}
echo call_user_func('user_fn');
echo '|';
echo call_user_func('strlen', 'hi');
echo '|';
class C
{
    public static function f()
    {
        return 9;
    }
}
echo call_user_func('C::f');
echo '|';
echo call_user_func(['C', 'f']);
echo '|';
echo call_user_func('add', 1, 2);
echo '|';
echo call_user_func('add', ...[10, 20]);
--EXPECT--
4|2|9|9|3|30
