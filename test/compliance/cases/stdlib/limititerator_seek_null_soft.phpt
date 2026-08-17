--TEST--
LimitIterator::seek(null) — soft-null DEP then seek 0 (#31676)
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
$l = new LimitIterator(new ArrayIterator([10, 20, 30]), 0, 3);
$l->rewind();
try {
    $l->seek(null);
    echo 'cur=' . $l->current() . ' key=' . $l->key() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
?>
--EXPECT--
DEP:LimitIterator::seek(): Passing null to parameter #1 ($offset) of type int is deprecated
cur=10 key=0
