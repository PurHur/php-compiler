--TEST--
ext/odbc odbc_connect invalid DSN → false + warning (#6293)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
declare(strict_types=1);
$warned = false;
set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
    if (E_WARNING === $errno && str_contains($errstr, 'SQLConnect')) {
        $warned = true;
        return true;
    }
    return false;
});
$bad = odbc_connect('DSN=php-compiler-no-such-dsn', 'u', 'p');
echo 'bad=', var_export($bad, true), "\n";
echo 'warned=', (int) $warned, "\n";
echo 'state=', var_export(odbc_error(), true), "\n";
echo 'msg_nonempty=', (int) ('' !== odbc_errormsg()), "\n";
?>
--EXPECT--
bad=false
warned=1
state='HY000'
msg_nonempty=1
