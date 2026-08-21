--TEST--
AOT: SplFileObject::current + foreach compile (phpc_explode_find_delim scope, #33521)
--FILE--
<?php
$p = sys_get_temp_dir() . '/phpc_sfo_cur_33521.txt';
file_put_contents($p, "a\nb\n");
$o = new SplFileObject($p);
echo json_encode($o->current()), "\n";
foreach ($o as $k => $v) {
    echo $k, '=', json_encode($v), ';';
}
echo "\n";
@unlink($p);
--EXPECT--
"a\n"
0="a\n";1="b\n";2="";
