<?php
error_reporting(E_ALL);
$a = [];
$a['k'] += 1;
echo 'plus=', $a['k'], "\n";

$b = [];
$b['k']++;
echo 'inc=', $b['k'], "\n";

$c = [];
$c['x']['y'] += 1;
echo 'nest=', $c['x']['y'], "\n";

$d = [];
$d['k'] .= 'z';
echo 'dot=', $d['k'], "\n";

$e = null;
$e['k'] += 1;
echo 'null=', $e['k'], "\n";
