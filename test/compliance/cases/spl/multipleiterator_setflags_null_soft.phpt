--TEST--
MultipleIterator::setFlags(null) — soft-null DEP cites parameter #1 (#31795)
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
$m = new MultipleIterator();
$m->attachIterator(new ArrayIterator([1]));
try {
    $m->setFlags(null);
    echo 'flags=' . $m->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
?>
--EXPECT--
DEP:MultipleIterator::setFlags(): Passing null to parameter #1 ($flags) of type int is deprecated
flags=0
