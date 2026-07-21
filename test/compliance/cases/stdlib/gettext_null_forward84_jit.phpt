--TEST--
stdlib gettext/_/dgettext(null) soft-null DEP+'' on 8.4 forward JIT (#21581, reverts #20209, ext/gettext/gettext.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$seenDep = 0;
set_error_handler(static function (int $no, string $msg) use (&$seenDep): bool {
    if (E_DEPRECATED === $no) {
        $seenDep++;
        return true;
    }
    return false;
});
foreach (['gettext', '_'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ': ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ' ', $e->getMessage(), "\n";
    }
}
try {
    $r = dgettext('messages', null);
    echo 'dgettext: ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'dgettext: ', get_class($e), ' ', $e->getMessage(), "\n";
}
try {
    bindtextdomain(null, '/tmp');
    echo "bindtextdomain: ok\n";
} catch (ValueError $e) {
    echo 'bindtextdomain: ValueError ', (str_contains($e->getMessage(), 'must not be empty') ? 'empty' : $e->getMessage()), "\n";
} catch (Throwable $e) {
    echo 'bindtextdomain: ', get_class($e), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seenDep >= 3), "\n";
echo 'ok_string=', gettext('hello'), "\n";
?>
--EXPECT--
gettext: ''
_: ''
dgettext: ''
bindtextdomain: ValueError empty
depr=1
ok_string=hello
