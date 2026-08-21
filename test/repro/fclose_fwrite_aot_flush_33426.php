<?php
// AOT: fwrite+fclose must flush bytes to disk (#33426). Peer StreamGlobalsJit clear #30792.
$f = sys_get_temp_dir().'/phpc_fw'.getmypid().'.txt';
@unlink($f);
$h = fopen($f, 'w');
var_dump(fwrite($h, 'hi'));
var_dump(fclose($h));
clearstatcache();
var_dump(file_get_contents($f));
@unlink($f);
