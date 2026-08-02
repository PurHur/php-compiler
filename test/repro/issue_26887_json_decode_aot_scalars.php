<?php

declare(strict_types=1);

/**
 * #26887 — AOT json_decode assoc + scalar types must match Zend (bool not int).
 */
$t = json_decode('true');
$f = json_decode('false');
$n = json_decode('null');
$i = json_decode('42');
$d = json_decode('3.14');
$s = json_decode('"hi"');
$a = json_decode('{"a":1}', true);
$o = json_decode('{"a":1}');

echo gettype($t), ' ', ($t === true) ? 'T' : 'x', "\n";
echo gettype($f), ' ', ($f === false) ? 'F' : 'x', "\n";
echo gettype($n), ' ', (null === $n) ? 'N' : 'x', "\n";
echo gettype($i), ' ', $i, "\n";
echo gettype($d), ' ', $d, "\n";
echo gettype($s), ' ', $s, "\n";
echo gettype($a), ' ', $a['a'], "\n";
echo gettype($o), ' ', $o->a, "\n";
