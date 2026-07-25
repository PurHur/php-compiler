<?php
/**
 * After @silence, concat/interp as call args must be the string — not the @ return (#23045).
 */
$d = "/tmp/phpc_silence_concat_23045";
@mkdir($d);
@strlen($d);
var_export("$d/y");
echo "\n";
printf("%s\n", $d . "/y");
@strlen($d);
$written = file_put_contents($d . "/t.txt", "ok");
echo (is_int($written) && $written > 0) ? "file-ok\n" : "file-fail\n";
@unlink($d . "/t.txt");
@rmdir($d);
