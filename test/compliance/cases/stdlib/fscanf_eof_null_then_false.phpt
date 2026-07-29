--TEST--
stdlib fscanf() first EOF after last line — false not NULL (#24448, ext/standard/file.c)
--FILE--
<?php
$f = fopen('php://temp', 'r+');
fwrite($f, "1 2\n");
rewind($f);
$r0 = fscanf($f, '%d %d');
$r1 = fscanf($f, '%d %d');
$r2 = fscanf($f, '%d %d');
fclose($f);
echo is_array($r0) && ($r0[0] ?? null) === 1 && ($r0[1] ?? null) === 2 ? 'ok0' : 'fail0', "\n";
echo false === $r1 ? 'ok1' : ('fail1:'.var_export($r1, true)), "\n";
echo false === $r2 ? 'ok2' : ('fail2:'.var_export($r2, true)), "\n";
--EXPECT--
ok0
ok1
ok2
