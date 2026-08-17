--TEST--
FilesystemIterator::setFlags(null) — soft-null DEP then flags=0 (#31722)
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
$tmpdir = sys_get_temp_dir() . '/phpc_fsi_setflags_' . getmypid();
@mkdir($tmpdir);
file_put_contents("$tmpdir/a.txt", 'x');
try {
    $it = new FilesystemIterator($tmpdir);
    $it->setFlags(null);
    echo 'flags=' . $it->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
@system('rm -rf ' . escapeshellarg($tmpdir));
?>
--EXPECT--
DEP:FilesystemIterator::setFlags(): Passing null to parameter #1 ($flags) of type int is deprecated
flags=0
