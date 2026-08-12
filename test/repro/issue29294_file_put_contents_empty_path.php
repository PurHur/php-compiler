<?php

declare(strict_types=1);

/**
 * #29294 — file_put_contents('') empty-path ValueError must match Zend:
 * "Path cannot be empty" (php-src ext/standard/file.c / zend_parse_arg_path).
 *
 * Residual of #29268: fopen/file_get_contents/hash_file were fixed; write path still
 * leaked host PHP's "Path cannot be empty" via VmFsWritePure → host fopen.
 */
$expected = 'Path cannot be empty';
try {
    file_put_contents('', 'x');
    fwrite(STDERR, "fail: file_put_contents(\"\") expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'file_put_contents:', $e->getMessage(), "\n";
}
echo "ok\n";
