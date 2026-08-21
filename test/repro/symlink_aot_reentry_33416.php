<?php
// AOT: symlink() must create the link (#33416). Peer unlink #33412 / mkdir #33402.
$d = sys_get_temp_dir().'/phpc_sy'.getmypid();
mkdir($d);
$t = $d.'/t';
$l = $d.'/l';
file_put_contents($t, 'x');
var_dump(symlink($t, $l));
var_dump(is_link($l));
unlink($l);
unlink($t);
rmdir($d);
