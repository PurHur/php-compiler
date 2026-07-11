<?php

declare(strict_types=1);

$out = 'hello';
$x = $out;

if (!extension_loaded('curl') && str_contains($out, 'cURL')) {
    echo "branch1\n";
}

var_dump($out, $x);
