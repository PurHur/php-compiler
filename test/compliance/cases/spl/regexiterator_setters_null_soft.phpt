--TEST--
RegexIterator setMode/setFlags/setPregFlags(null) — soft-null DEP then 0 (#31748)
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
$it = new RegexIterator(new ArrayIterator(['a1']), '/\d/');
foreach (['setMode' => 'getMode', 'setFlags' => 'getFlags', 'setPregFlags' => 'getPregFlags'] as $set => $get) {
    try {
        $it->$set(null);
        echo $set, '=', $it->$get(), "\n";
    } catch (Throwable $e) {
        echo $set, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
DEP:RegexIterator::setMode(): Passing null to parameter #1 ($mode) of type int is deprecated
setMode=0
DEP:RegexIterator::setFlags(): Passing null to parameter #1 ($flags) of type int is deprecated
setFlags=0
DEP:RegexIterator::setPregFlags(): Passing null to parameter #1 ($pregFlags) of type int is deprecated
setPregFlags=0
