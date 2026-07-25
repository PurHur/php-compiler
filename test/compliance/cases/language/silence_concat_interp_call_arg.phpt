--TEST--
After @silence, concat/interp call args must not bind prior return (#23045, re-#21439)
--FILE--
<?php
$d = "/tmp/phpc_silence_concat_23045";
@mkdir($d);
@strlen($d);
var_export("$d/y");
echo "\n";
printf("%s\n", $d . "/y");
@strlen($d);
$s = "$d/y";
var_export($s);
echo "\n";
@strlen($d);
echo $d . "/y", "\n";
@strlen($d);
$written = file_put_contents($d . "/t.txt", "ok");
echo (is_int($written) && $written > 0 && is_file($d . "/t.txt")) ? "file-ok\n" : "file-fail\n";
@unlink($d . "/t.txt");
@rmdir($d);
?>
--EXPECT--
'/tmp/phpc_silence_concat_23045/y'
/tmp/phpc_silence_concat_23045/y
'/tmp/phpc_silence_concat_23045/y'
/tmp/phpc_silence_concat_23045/y
file-ok
