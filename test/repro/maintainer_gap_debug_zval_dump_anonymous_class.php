<?php

declare(strict_types=1);

$o = new class {};
ob_start();
debug_zval_dump($o);
$out = ob_get_clean();
if (!str_contains($out, 'object(class@anonymous)')) {
    echo "fail: missing class@anonymous label\n";
    exit(1);
}
if (str_contains($out, "\0")) {
    echo "fail: NUL suffix in output\n";
    exit(1);
}
echo "ok\n";
