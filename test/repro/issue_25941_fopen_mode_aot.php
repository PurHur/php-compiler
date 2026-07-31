<?php
/**
 * Issue #25941 AOT/JIT return-value probe (no set_error_handler — handler shim TypeErrors under AOT).
 * NestedJIT plain-file fopen does not execute VmFsOpenPure; VM/JIT cover warning text.
 * Run: php bin/jit.php test/repro/issue_25941_fopen_mode_aot.php
 */
error_reporting(0);
$path = sys_get_temp_dir().'/phpc_fm3_aot_'.getmypid();
file_put_contents($path, 'x');
foreach (['', 'q', 'r'] as $mode) {
    $h = @fopen($path, $mode);
    echo 'MODE=', var_export($mode, true),
        ' false=', var_export($h === false, true),
        ' type=', gettype($h), "\n";
    if (\is_resource($h)) {
        fclose($h);
    }
}
@unlink($path);
