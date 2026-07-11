<?php

declare(strict_types=1);

ob_start();
debug_zval_dump(1);
$buf = ob_get_clean();
if (!is_string($buf) || strlen($buf) < 5 || !str_contains($buf, 'int(1)')) {
    fwrite(STDERR, "expected buffered int(1) dump, got: ".var_export($buf, true)."\n");
    exit(1);
}
echo "ok\n";
