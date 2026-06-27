--TEST--
stdlib session_name('') — Warning and unchanged PHPSESSID (#12563, ext/session/session.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
session_name('');
echo session_name() === 'PHPSESSID' ? "name_ok\n" : "name_bad\n";
$warned = false;
foreach ($warnings as $message) {
    if (false !== strpos($message, 'cannot be numeric or empty')) {
        $warned = true;
        break;
    }
}
echo $warned ? "warn_ok\n" : "warn_bad\n";
?>
--EXPECT--
name_ok
warn_ok
