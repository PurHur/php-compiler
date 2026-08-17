--TEST--
DirectoryIterator::seek(null) — soft-null DEP then key=0 (#31723)
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
$tmpdir = sys_get_temp_dir() . '/phpc_di_seek_' . getmypid();
@mkdir($tmpdir);
file_put_contents("$tmpdir/a.txt", 'x');
try {
    $it = new DirectoryIterator($tmpdir);
    $it->seek(null);
    echo 'key=' . $it->key() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
@system('rm -rf ' . escapeshellarg($tmpdir));
?>
--EXPECT--
DEP:DirectoryIterator::seek(): Passing null to parameter #1 ($offset) of type int is deprecated
key=0
