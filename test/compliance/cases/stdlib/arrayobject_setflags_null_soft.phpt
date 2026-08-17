--TEST--
ArrayObject/ArrayIterator::setFlags(null) — soft-null DEP then flags=0 (#31696)
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
foreach (['ArrayObject' => new ArrayObject([1]), 'ArrayIterator' => new ArrayIterator([1])] as $label => $a) {
    echo "== $label ==\n";
    try {
        $a->setFlags(null);
        echo 'flags=' . $a->getFlags() . "\n";
    } catch (Throwable $e) {
        echo get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
?>
--EXPECT--
== ArrayObject ==
DEP:ArrayObject::setFlags(): Passing null to parameter #1 ($flags) of type int is deprecated
flags=0
== ArrayIterator ==
DEP:ArrayIterator::setFlags(): Passing null to parameter #1 ($flags) of type int is deprecated
flags=0
