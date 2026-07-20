--TEST--
stdlib password_hash/gzencode/implode soft-null on 8.4 (#21210)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
    ['password_hash', [null, PASSWORD_DEFAULT], null],
    ['gzencode', [null], null],
    ['implode', [null, ['a', 'b']], 'ab'],
];
foreach ($cases as [$f, $a, $expect]) {
    try {
        $r = $f(...$a);
        if (null === $expect) {
            if ('password_hash' === $f) {
                $ok = is_string($r) && str_starts_with($r, '$2y$');
            } else {
                $ok = strlen($r) === strlen(gzencode(''));
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
password_hash OK
DEP
gzencode OK
DEP
implode OK
