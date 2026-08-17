--TEST--
RecursiveIteratorIterator::setMaxDepth(null) — soft-null DEP then max=0 (#31695)
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
$r = new RecursiveIteratorIterator(new RecursiveArrayIterator([1]));
try {
    $r->setMaxDepth(null);
    echo 'max=';
    var_export($r->getMaxDepth());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
?>
--EXPECT--
DEP:RecursiveIteratorIterator::setMaxDepth(): Passing null to parameter #1 ($maxDepth) of type int is deprecated
max=0
