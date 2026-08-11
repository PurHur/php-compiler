<?php
declare(strict_types=1);
$fi = finfo_open(FILEINFO_MIME_TYPE);
try {
    echo 'file ', var_export(finfo_file($fi, null), true), "\n";
} catch (Throwable $e) {
    echo 'file ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo 'buf ', var_export(finfo_buffer($fi, null), true), "\n";
} catch (Throwable $e) {
    echo 'buf ', get_class($e), ': ', $e->getMessage(), "\n";
}
