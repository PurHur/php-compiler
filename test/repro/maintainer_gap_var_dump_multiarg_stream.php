<?php

declare(strict_types=1);

$s = fopen('php://memory', 'r+');
var_dump($s, gettype($s));
echo 'gettype=', gettype($s), "\n";
$r = fwrite($s, 'hi');
echo 'fwrite=', var_export($r, true), "\n";
