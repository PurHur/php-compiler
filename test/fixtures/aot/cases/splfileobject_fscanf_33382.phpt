--TEST--
SPL SplFileObject::fscanf AOT (#33382, ext/spl/spl_directory.c)
--FILE--
<?php
$p = sys_get_temp_dir().'/spl_fscanf_33382_'.getmypid().'.txt';
file_put_contents($p, "42 hello\n");
$o = new SplFileObject($p);
$row = $o->fscanf('%d %s');
@unlink($p);
echo 'row=', json_encode($row), "\n";
$eof = $o->fscanf('%d %s');
echo 'eof=', json_encode($eof), "\n";
?>
--EXPECT--
row=[42,"hello"]
eof=null
