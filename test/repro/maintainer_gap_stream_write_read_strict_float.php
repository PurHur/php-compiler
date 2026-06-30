<?php

declare(strict_types=1);

$path = sys_get_temp_dir().'/phpc_stream_strict_float_'.getmypid().'.txt';
file_put_contents($path, 'abcdef');

$fh = fopen($path, 'r+');
if (false === $fh) {
    fwrite(STDERR, "fail: could not open temp file\n");
    exit(1);
}

$checks = [
    ['fwrite', static function () use ($fh): void {
        fwrite($fh, 'abc', 1.9);
    }, ['fwrite(): Argument #3 ($length) must be of type ?int, float given']],
    ['fgets', static function () use ($fh): void {
        rewind($fh);
        fgets($fh, 1.9);
    }, ['fgets(): Argument #2 ($length) must be of type ?int, float given']],
];

foreach ($checks as [$name, $fn, $needles]) {
    try {
        $fn();
        fwrite(STDERR, "fail: expected TypeError for float length in {$name}() under strict_types\n");
        fclose($fh);
        @unlink($path);
        exit(1);
    } catch (TypeError $e) {
        $matched = false;
        foreach ($needles as $needle) {
            if (str_contains($e->getMessage(), $needle)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            fwrite(STDERR, "{$name} unexpected message: ".$e->getMessage()."\n");
            fclose($fh);
            @unlink($path);
            exit(1);
        }
    }
}

fclose($fh);

$src = fopen($path, 'r');
$dst = fopen('php://memory', 'w+');
if (false === $src || false === $dst) {
    fwrite(STDERR, "fail: could not open streams for stream_copy_to_stream\n");
    @unlink($path);
    exit(1);
}

try {
    stream_copy_to_stream($src, $dst, 1.9);
    fwrite(STDERR, "fail: expected TypeError for float maxlength in stream_copy_to_stream() under strict_types\n");
    fclose($src);
    fclose($dst);
    @unlink($path);
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'stream_copy_to_stream(): Argument #3 ($length) must be of type ?int, float given')
        && !str_contains($e->getMessage(), 'stream_copy_to_stream(): Argument #3 ($maxlength) must be of type ?int, float given')) {
        fwrite(STDERR, 'stream_copy_to_stream unexpected message: '.$e->getMessage()."\n");
        fclose($src);
        fclose($dst);
        @unlink($path);
        exit(1);
    }
}

fclose($src);
fclose($dst);
@unlink($path);
echo "ok\n";
