<?php

declare(strict_types=1);

$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
try {
    $result = iptcembed('', $jpeg);
    echo 'result=', var_export($result, true), "\n";
    exit(1);
} catch (ValueError $e) {
    echo 'ok: ', $e->getMessage(), "\n";
    exit(0);
}
