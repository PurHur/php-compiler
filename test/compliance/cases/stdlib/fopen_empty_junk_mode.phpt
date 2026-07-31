--TEST--
stdlib fopen() empty/junk mode — false + invalid-mode warning (#25941)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_fopen_mode_' . getmypid();
file_put_contents($path, 'x');
// Untyped handler — typed errno int64 is not supported for AOT JIT callbacks.
function fopen_empty_junk_mode_warn($n, $s)
{
    echo 'WARN:', $s, "\n";
    return true;
}
set_error_handler('fopen_empty_junk_mode_warn');
foreach (['', 'q'] as $mode) {
    echo 'MODE=', var_export($mode, true), "\n";
    $h = fopen($path, $mode);
    echo 'type=', gettype($h), ' false=', var_export($h === false, true), "\n";
}
$h = @fopen($path, 'r');
echo 'ok_type=', gettype($h), "\n";
if (is_resource($h)) {
    fclose($h);
}
@unlink($path);
--EXPECTF--
MODE=''
WARN:fopen(%s): Failed to open stream: `' is not a valid mode for fopen
type=boolean false=true
MODE='q'
WARN:fopen(%s): Failed to open stream: `q' is not a valid mode for fopen
type=boolean false=true
ok_type=resource
