--TEST--
Language: call_user_func invokes __call / __callStatic (issue #25747, Zend/zend_API.c)
--FILE--
<?php
class C
{
    public function __call($n, $a)
    {
        return 'c:' . $n . ':' . implode(',', $a);
    }

    public static function __callStatic($n, $a)
    {
        return 'cs:' . $n . ':' . implode(',', $a);
    }
}

$c = new C();
var_export(is_callable([$c, 'missing']));
echo "\n";
var_export(is_callable(['C', 'missing']));
echo "\n";
echo call_user_func([$c, 'missing']) . "\n";
echo call_user_func(['C', 'missing']) . "\n";
echo call_user_func('C::missing') . "\n";
echo call_user_func([$c, 'missing'], 'a', 'b') . "\n";
echo call_user_func_array(['C', 'missing'], ['x']) . "\n";

class ParentMagic
{
    public static function __callStatic($n, $a)
    {
        return 'p:' . $n;
    }
}

class Child extends ParentMagic
{
}

echo call_user_func(['Child', 'nope']) . "\n";
--EXPECT--
true
true
c:missing:
cs:missing:
cs:missing:
c:missing:a,b
cs:missing:x
p:nope
