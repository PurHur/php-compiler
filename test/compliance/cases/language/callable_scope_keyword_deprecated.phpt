--TEST--
Language: self::/static::/parent:: callables emit E_DEPRECATED (#27915, zend_execute_API.c)
--FILE--
<?php
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        $deps[] = $msg;
    }

    return true;
});

class Base
{
    public static function m(): int
    {
        return 1;
    }
}

class Child extends Base
{
    public static function probe(): void
    {
        $ok = is_callable('self::m')
            && is_callable('static::m')
            && is_callable('parent::m')
            && is_callable(['self', 'm'])
            && is_callable(['static', 'm'])
            && is_callable(['parent', 'm']);
        echo 'callable=', $ok ? '1' : '0', "\n";
        echo 'cuf=', (string) call_user_func('self::m'), "\n";
        echo 'cuf_arr=', (string) call_user_func(['parent', 'm']), "\n";
        echo 'syn=', is_callable('self::m', true) ? '1' : '0', "\n";
        $c = 'self::m';
        try {
            $c();
            echo "direct-ok\n";
        } catch (Error $e) {
            echo $e->getMessage(), "\n";
        }
    }
}

Child::probe();
echo 'dep_count=', (string) count($deps), "\n";
sort($deps);
foreach ($deps as $d) {
    echo 'DEP:', $d, "\n";
}
?>
--EXPECT--
callable=1
cuf=1
cuf_arr=1
syn=1
Class "self" not found
dep_count=8
DEP:Use of "parent" in callables is deprecated
DEP:Use of "parent" in callables is deprecated
DEP:Use of "parent" in callables is deprecated
DEP:Use of "self" in callables is deprecated
DEP:Use of "self" in callables is deprecated
DEP:Use of "self" in callables is deprecated
DEP:Use of "static" in callables is deprecated
DEP:Use of "static" in callables is deprecated
