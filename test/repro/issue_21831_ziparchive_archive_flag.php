<?php
/**
 * Issue #21831 — ZipArchive::setArchiveFlag/getArchiveFlag + AFL_* (php-src ext/zip/php_zip.c).
 */
declare(strict_types=1);

$failed = 0;

foreach (['setArchiveFlag', 'getArchiveFlag'] as $m) {
    if (!method_exists(ZipArchive::class, $m)) {
        echo "missing method $m\n";
        ++$failed;
    }
}

foreach ([
    'AFL_RDONLY' => 2,
    'AFL_IS_TORRENTZIP' => 4,
    'AFL_WANT_TORRENTZIP' => 8,
    'AFL_CREATE_OR_KEEP_FILE_FOR_EMPTY_ARCHIVE' => 16,
    'FL_UNCHANGED' => 8,
] as $name => $want) {
    if (!defined('ZipArchive::'.$name)) {
        echo "missing const $name\n";
        ++$failed;
        continue;
    }
    $got = constant('ZipArchive::'.$name);
    if ($got !== $want) {
        echo "const $name want $want got $got\n";
        ++$failed;
    }
}

$path = sys_get_temp_dir().'/phpc_zip_afl_'.getmypid().'.zip';
@unlink($path);
$zip = new ZipArchive();
// CREATE|OVERWRITE = 1|8 = 9 (avoid VM class-const bitwise OR quirk in this repro)
$zip->open($path, 9);

if (1 !== $zip->getArchiveFlag(ZipArchive::AFL_RDONLY)) {
    // expect 0 initially
}
if (0 !== $zip->getArchiveFlag(ZipArchive::AFL_RDONLY)) {
    echo "initial AFL_RDONLY want 0 got ", $zip->getArchiveFlag(ZipArchive::AFL_RDONLY), "\n";
    ++$failed;
}

if (true !== $zip->setArchiveFlag(ZipArchive::AFL_RDONLY, 1)) {
    echo "set AFL_RDONLY failed\n";
    ++$failed;
}
if (1 !== $zip->getArchiveFlag(ZipArchive::AFL_RDONLY)) {
    echo "get AFL_RDONLY after set want 1\n";
    ++$failed;
}
if ($zip->isWritable()) {
    echo "isWritable should be false after AFL_RDONLY\n";
    ++$failed;
}

// libzip: AFL_RDONLY cannot be cleared via setArchiveFlag
if (false !== $zip->setArchiveFlag(ZipArchive::AFL_RDONLY, 0)) {
    echo "clear AFL_RDONLY should fail\n";
    ++$failed;
}
if (1 !== $zip->getArchiveFlag(ZipArchive::AFL_RDONLY)) {
    echo "AFL_RDONLY still set after failed clear\n";
    ++$failed;
}

// FL_UNCHANGED sees open-time (0)
if (0 !== $zip->getArchiveFlag(ZipArchive::AFL_RDONLY, ZipArchive::FL_UNCHANGED)) {
    echo "FL_UNCHANGED AFL_RDONLY want 0\n";
    ++$failed;
}

if (true !== $zip->setArchiveFlag(ZipArchive::AFL_WANT_TORRENTZIP, 1)) {
    echo "set WANT_TORRENTZIP failed\n";
    ++$failed;
}
if (1 !== $zip->getArchiveFlag(ZipArchive::AFL_WANT_TORRENTZIP)) {
    echo "get WANT_TORRENTZIP want 1\n";
    ++$failed;
}

try {
    $zip->setArchiveFlag();
    echo "arity0 uncaught\n";
    ++$failed;
} catch (ArgumentCountError $e) {
    // ok
}

$zip->close();
@unlink($path);

echo $failed > 0 ? "FAIL\n" : "ok\n";
exit($failed > 0 ? 1 : 0);
