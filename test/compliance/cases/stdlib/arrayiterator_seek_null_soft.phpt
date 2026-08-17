--TEST--
ArrayIterator::seek(null) — soft-null DEP then offset 0 (#31730)
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
$ai = new ArrayIterator([10, 20, 30]);
try {
    $ai->seek(null);
    echo 'key=' . $ai->key() . ' current=' . $ai->current() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
?>
--EXPECT--
DEP:ArrayIterator::seek(): Passing null to parameter #1 ($offset) of type int is deprecated
key=0 current=10
