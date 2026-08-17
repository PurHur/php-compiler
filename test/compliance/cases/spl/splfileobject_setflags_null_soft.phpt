--TEST--
SplFileObject::setFlags(null) — soft-null DEP then flags=0 (#31796)
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
$tmp = tempnam(sys_get_temp_dir(), 'sfo31796');
file_put_contents($tmp, "a\nb\n");
try {
    $f = new SplFileObject($tmp);
    $f->setFlags(null);
    echo 'flags=' . $f->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
@unlink($tmp);
?>
--EXPECT--
DEP:SplFileObject::setFlags(): Passing null to parameter #1 ($flags) of type int is deprecated
flags=0
