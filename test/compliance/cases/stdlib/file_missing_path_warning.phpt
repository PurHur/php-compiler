--TEST--
stdlib file() missing path emits E_WARNING Failed to open stream (#26695, ext/standard/file.c)
--FILE--
<?php
error_clear_last();
$path = '/no/such/file_compliance_gap_'.getmypid();
$handlerSaw = false;
set_error_handler(static function (int $no, string $str) use (&$handlerSaw): bool {
    if (E_WARNING === $no && str_contains($str, 'Failed to open stream')) {
        $handlerSaw = true;
    }
    return true;
});
$r = file($path);
restore_error_handler();
echo 'return=', var_export($r, true), "\n";
echo 'handler=', $handlerSaw ? 'yes' : 'no', "\n";

error_clear_last();
@file($path);
$e = error_get_last();
echo 'at_type=', null === $e ? 'null' : (string) $e['type'], "\n";
echo 'at_open=', null !== $e && str_contains((string) $e['message'], 'Failed to open stream') ? 'yes' : 'no', "\n";

$ok = file(__FILE__);
echo 'happy=', is_array($ok) ? 'array' : 'bad', "\n";
?>
--EXPECT--
return=false
handler=yes
at_type=2
at_open=yes
happy=array
