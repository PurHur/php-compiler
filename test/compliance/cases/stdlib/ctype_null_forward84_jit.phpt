--TEST--
stdlib ctype_*(null) E_DEPRECATED+false on 8.4 forward JIT (#20611, re-#20252, ext/ctype/ctype.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
foreach (['ctype_alnum', 'ctype_digit', 'ctype_space'] as $fn) {
    $seen = [];
    set_error_handler(static function (int $no, string $str) use (&$seen): bool {
        $seen[] = [$no, $str];
        return true;
    });
    try {
        $result = $fn(null);
        $caught = '';
    } catch (Throwable $e) {
        $result = null;
        $caught = get_class($e);
    }
    restore_error_handler();
    $depr = 0;
    foreach ($seen as [$no, $str]) {
        if (E_DEPRECATED === $no && str_contains($str, $fn.'(): Argument of type null will be interpreted as string in the future')) {
            $depr = 1;
        }
    }
    echo $fn, ':result=', var_export($result, true), ' depr=', $depr, ' err=', $caught, "\n";
}
echo 'ok_digit=', (int) ctype_digit('9'), "\n";
?>
--EXPECT--
ctype_alnum:result=false depr=1 err=
ctype_digit:result=false depr=1 err=
ctype_space:result=false depr=1 err=
ok_digit=1
