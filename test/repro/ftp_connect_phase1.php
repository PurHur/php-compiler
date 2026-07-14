<?php

declare(strict_types=1);

enum Host: string
{
    case Local = '127.0.0.1';
}

var_dump(function_exists('ftp_connect'));
$conn = @ftp_connect('127.0.0.1', 21, 1);
var_dump($conn);

try {
    ftp_connect(Host::Local);
    echo "enum: no error\n";
} catch (TypeError $e) {
    echo 'enum: TypeError ', $e->getMessage(), "\n";
}
