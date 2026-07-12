<?php

declare(strict_types=1);

$tmpdir = sys_get_temp_dir() . '/phpc_zip_proc_' . bin2hex(random_bytes(4));
mkdir($tmpdir, 0777, true);
$archive = $tmpdir . '/test.zip';
$payload = 'zip procedural payload';

$zip = new ZipArchive();
if (true !== $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    fwrite(STDERR, "fail: could not create fixture archive\n");
    exit(1);
}
$zip->addFromString('hello.txt', $payload);
$zip->close();

try {
    if (!function_exists('zip_open')) {
        fwrite(STDERR, "fail: zip_open missing\n");
        exit(1);
    }

    $zh = zip_open($archive);
    if (!is_resource($zh)) {
        fwrite(STDERR, "fail: zip_open did not return resource\n");
        exit(1);
    }
    if ('Zip Archive' !== get_resource_type($zh)) {
        fwrite(STDERR, 'fail: archive type=' . get_resource_type($zh) . "\n");
        exit(1);
    }

    $entry = zip_read($zh);
    if (!is_resource($entry)) {
        fwrite(STDERR, "fail: zip_read did not return resource\n");
        exit(1);
    }
    if ('Zip Entry' !== get_resource_type($entry)) {
        fwrite(STDERR, 'fail: entry type=' . get_resource_type($entry) . "\n");
        exit(1);
    }

    $name = zip_entry_name($entry);
    if ('hello.txt' !== $name) {
        fwrite(STDERR, "fail: entry name={$name}\n");
        exit(1);
    }

    $size = zip_entry_filesize($entry);
    if (\strlen($payload) !== $size) {
        fwrite(STDERR, "fail: entry size={$size}\n");
        exit(1);
    }

    if (!zip_entry_open($zh, $entry)) {
        fwrite(STDERR, "fail: zip_entry_open\n");
        exit(1);
    }

    $data = zip_entry_read($entry, $size);
    if ($payload !== $data) {
        fwrite(STDERR, "fail: entry data mismatch\n");
        exit(1);
    }

    if (!zip_entry_close($entry)) {
        fwrite(STDERR, "fail: zip_entry_close\n");
        exit(1);
    }

    if (false !== zip_read($zh)) {
        fwrite(STDERR, "fail: expected false after last entry\n");
        exit(1);
    }

    if (!zip_close($zh)) {
        fwrite(STDERR, "fail: zip_close\n");
        exit(1);
    }

    echo "ok\n";
} finally {
    @unlink($archive);
    @rmdir($tmpdir);
}
