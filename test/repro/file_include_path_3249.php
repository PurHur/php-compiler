<?php
$incDir = sys_get_temp_dir() . '/phpc_file_inc_repro_' . getmypid();
mkdir($incDir);
file_put_contents($incDir . '/repro.inc', "found\n");
$old = set_include_path($incDir);
$lines = file('repro.inc', FILE_USE_INCLUDE_PATH);
echo $lines[0] ?? 'fail', "\n";
$bad = file('missing_file_' . getmypid() . '.inc', FILE_USE_INCLUDE_PATH);
echo $bad === false ? "false\n" : "bad\n";
set_include_path($old);
unlink($incDir . '/repro.inc');
rmdir($incDir);
