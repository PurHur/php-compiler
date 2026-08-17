--TEST--
FilesystemIterator/RecursiveDirectoryIterator/GlobIterator::__construct(null $flags) — soft-null DEP then flags=0 (#31721)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
$tmpdir = sys_get_temp_dir() . '/phpc_fs_null_flags_phpt_' . getmypid();
@mkdir($tmpdir);
file_put_contents("$tmpdir/a.txt", 'x');
try {
    $it = new FilesystemIterator($tmpdir, null);
    echo 'fs=' . $it->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
try {
    $it = new RecursiveDirectoryIterator($tmpdir, null);
    echo 'rdi=' . $it->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
try {
    $it = new GlobIterator($tmpdir . '/*', null);
    echo 'gi=' . $it->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
@system('rm -rf ' . escapeshellarg($tmpdir));
?>
--EXPECT--
DEP:FilesystemIterator::__construct(): Passing null to parameter #2 ($flags) of type int is deprecated
fs=0
DEP:RecursiveDirectoryIterator::__construct(): Passing null to parameter #2 ($flags) of type int is deprecated
rdi=0
DEP:GlobIterator::__construct(): Passing null to parameter #2 ($flags) of type int is deprecated
gi=0
