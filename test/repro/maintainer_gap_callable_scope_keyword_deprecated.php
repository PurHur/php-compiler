<?php

/**
 * #27915 — self::/static::/parent:: string callables emit E_DEPRECATED (zend_execute_API.c).
 *
 * Resolution still succeeds (is_callable → true; call_user_func invokes). Direct $c() still
 * fatals with Class "self" not found.
 */
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
        $ok = is_callable('self::m') && is_callable('static::m') && is_callable('parent::m');
        echo 'callable=', $ok ? '1' : '0', "\n";
        echo 'cuf=', (string) call_user_func('self::m'), "\n";
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
