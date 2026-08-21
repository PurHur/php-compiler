<?php
/** Issue #33521 — SplFileObject::current + foreach thin AOT (phpc_explode_find_delim scope). */
$p = sys_get_temp_dir() . '/phpc_spl_cur_' . getmypid() . '.txt';
file_put_contents($p, "a\nb\n");
$o = new SplFileObject($p);
echo json_encode($o->current()), "\n";
foreach ($o as $k => $v) {
    echo $k, '=', json_encode($v), ';';
}
echo "\n";
@unlink($p);
