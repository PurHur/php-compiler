<?php

/** Issue #12534 — stream_wrapper_register() rejects unknown wrapper class (php-src userspace.c). */
try {
    stream_wrapper_register('probe', 'NotAClass');
    echo "registered: ", var_export(true, true), "\n";
    echo "FAIL: Zend throws TypeError when wrapper class is not a valid class name\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
    if (!str_contains($e->getMessage(), 'NotAClass')) {
        echo "FAIL: message missing class name\n";
        exit(1);
    }
}
class ValidStream {
    public function stream_open(string $path, string $mode, int $options): bool
    {
        return true;
    }
    public function stream_read(int $count): string
    {
        return '';
    }
    public function stream_eof(): bool
    {
        return true;
    }
}
if (!stream_wrapper_register('valid', ValidStream::class)) {
    echo "FAIL: valid wrapper class should register\n";
    exit(1);
}
echo "ok\n";
