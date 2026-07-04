<?php

declare(strict_types=1);

$out = 'hello';

if (!extension_loaded('curl') && str_contains($out, 'cURL')) {
    echo "branch1\n";
}

if (extension_loaded('curl') && !str_contains($out, 'cURL')) {
    echo "branch2\n";
}

if (extension_loaded('mbstring') && !str_contains($out, 'Multibyte String Functions')) {
    echo "branch3\n";
}

if (extension_loaded('json') && !str_contains($out, 'JSON')) {
    echo "branch4\n";
}

var_dump($out);
