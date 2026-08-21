<?php
// AOT: link() must compile + create hard link (#33406). Peer StringSymlink #26323.
$a = sys_get_temp_dir().'/phpc_link_a'.getmypid();
$b = sys_get_temp_dir().'/phpc_link_b'.getmypid();
@unlink($a);
@unlink($b);
file_put_contents($a, 'x');
var_dump(link($a, $b));
var_dump(is_file($b));
var_dump(file_get_contents($b));
@unlink($a);
@unlink($b);
