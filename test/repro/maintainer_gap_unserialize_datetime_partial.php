<?php

declare(strict_types=1);

$blob = 'O:8:"DateTime":1:{s:4:"date";s:19:"2020-01-01 00:00:00";}';

try {
    unserialize($blob);
    echo "uncaught\n";
    exit(1);
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
