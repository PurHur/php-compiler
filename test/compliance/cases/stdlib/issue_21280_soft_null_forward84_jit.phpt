--TEST--
stdlib str_rot13/crypt/uniqid/gzcompress soft-null on 8.4 JIT (#21280)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
$cases = [
    ['str_rot13', [null], ''],
    ['crypt', [null, 'ab'], null],
    ['uniqid', [null], null],
    ['gzcompress', [null], null],
];
foreach ($cases as [$f, $a, $expect]) {
    try {
        $r = $f(...$a);
        if (null === $expect) {
            $ok = false;
            if ('gzcompress' === $f) {
                $ok = strlen($r) === strlen(gzcompress(''));
            } elseif ('uniqid' === $f) {
                $ok = is_string($r) && strlen($r) >= 13;
            } elseif ('crypt' === $f) {
                $ok = is_string($r) && strlen($r) > 0;
            }
            echo $f, ' ', ($ok ? 'OK' : 'BAD '.var_export($r, true)), "\n";
        } else {
            echo $f, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
        }
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
?>
--EXPECT--
DEP
str_rot13 OK
DEP
crypt OK
DEP
uniqid OK
DEP
gzcompress OK
