--TEST--
SPL SplFileObject/SplTempFileObject fread/fgetc/fscanf (#19804, ext/spl/spl_directory.c)
--FILE--
<?php
$p = tempnam(sys_get_temp_dir(), 'sf');
file_put_contents($p, 'abcdef');
$f = new SplFileObject($p);
echo var_export($f->fread(3), true), "\n";
unlink($p);

$t = new SplTempFileObject();
$t->fwrite('hello');
$t->rewind();
echo var_export($t->fread(2), true), "\n";
echo var_export($t->fgetc(), true), "\n";

$u = new SplTempFileObject();
$u->fwrite("42 x\n");
$u->rewind();
echo var_export($u->fscanf('%d %s'), true), "\n";
?>
--EXPECT--
'abc'
'he'
'l'
array (
  0 => 42,
  1 => 'x',
)
