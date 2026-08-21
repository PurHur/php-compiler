<?php
// AOT: symlink() must compile + create symlink (#33417). Peer StringLink #33406.
$t = sys_get_temp_dir().'/phpc_sym_t'.getmypid();
$l = sys_get_temp_dir().'/phpc_sym_l'.getmypid();
@unlink($l);
@unlink($t);
file_put_contents($t, 'x');
var_dump(symlink($t, $l));
var_dump(is_link($l));
var_dump(file_get_contents($l));
@unlink($l);
@unlink($t);
