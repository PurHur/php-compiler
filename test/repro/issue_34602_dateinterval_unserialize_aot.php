<?php
// #34602 — AOT unserialize(DateInterval): assign fold, literal NestedJIT, file-backed runtime.
declare(strict_types=1);

$i = new DateInterval('P1Y2M3DT4H5M6S');
$s = serialize($i);
$u = unserialize($s);
echo $u->format('%Y-%M-%D %H:%I:%S'), PHP_EOL;

// Literal Zend member wire (no serialize() fold stamp) — NestedJIT restore (#34602).
$lit = 'O:12:"DateInterval":10:{s:1:"y";i:1;s:1:"m";i:2;s:1:"d";i:3;s:1:"h";i:4;s:1:"i";i:5;s:1:"s";i:6;s:1:"f";d:0;s:6:"invert";i:0;s:4:"days";b:0;s:11:"from_string";b:0;}';
$ul = unserialize($lit);
echo $ul->format('%Y-%M-%D %H:%I:%S'), PHP_EOL;
echo $ul->y, ',', $ul->m, ',', $ul->d, PHP_EOL;

// True runtime payload (no compileTimeString) — file-backed residual (#34602).
$tmp = sys_get_temp_dir().'/phpc_34602_di_'.getmypid().'.ser';
file_put_contents($tmp, $s);
$uf = unserialize(file_get_contents($tmp));
@unlink($tmp);
echo get_class($uf), PHP_EOL;
echo $uf->y, '-', $uf->m, '-', $uf->d, ' ', $uf->h, ':', $uf->i, ':', $uf->s, PHP_EOL;
echo $uf->format('%Y-%M-%D %H:%I:%S'), PHP_EOL;
