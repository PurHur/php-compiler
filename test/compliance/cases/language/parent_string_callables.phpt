--TEST--
Language: parent::/self:: string callables — is_callable/call_user_func vs $c() (#25625, zend_execute_API.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);

class A
{
    public function f()
    {
        return 'A::f';
    }

    public static function s()
    {
        return 'A::s';
    }
}

class B extends A
{
    public function f()
    {
        return 'B::f';
    }

    public function report(): void
    {
        var_export(is_callable('parent::f'));
        echo "\n";
        var_export(is_callable(['parent', 'f']));
        echo "\n";
        var_export(call_user_func('parent::f'));
        echo "\n";
        var_export(call_user_func(['parent', 'f']));
        echo "\n";
        var_export(call_user_func('self::f'));
        echo "\n";
        $c = 'parent::f';
        try {
            $c();
            echo "direct-parent-ok\n";
        } catch (Error $e) {
            echo $e->getMessage(), "\n";
        }
        $c = 'self::f';
        try {
            $c();
            echo "direct-self-ok\n";
        } catch (Error $e) {
            echo $e->getMessage(), "\n";
        }
        $c = ['parent', 'f'];
        try {
            $c();
            echo "direct-arr-ok\n";
        } catch (Error $e) {
            echo $e->getMessage(), "\n";
        }
    }
}

(new B())->report();
echo 'global-is_callable=';
var_export(is_callable('parent::f'));
echo "\n";
try {
    call_user_func('parent::f');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
true
true
'A::f'
'A::f'
'B::f'
Class "parent" not found
Class "self" not found
Class "parent" not found
global-is_callable=false
call_user_func(): Argument #1 ($callback) must be a valid callback, cannot access "parent" when no class scope is active
