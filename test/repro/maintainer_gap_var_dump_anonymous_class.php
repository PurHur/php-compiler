<?php

declare(strict_types=1);

$o = new class {};
ob_start();
var_dump($o);
$dump = ob_get_clean();
if (!str_contains($dump, 'object(class@anonymous)')) {
    echo "fail: var_dump missing class@anonymous label\n";
    exit(1);
}
if (str_contains($dump, "\0")) {
    echo "fail: var_dump exposes internal NUL suffix\n";
    exit(1);
}

ob_start();
print_r($o);
$pr = ob_get_clean();
if (!str_contains($pr, 'class@anonymous Object')) {
    echo "fail: print_r missing class@anonymous Object header\n";
    exit(1);
}
if (str_contains($pr, "\0")) {
    echo "fail: print_r exposes internal NUL suffix\n";
    exit(1);
}

echo "ok\n";
