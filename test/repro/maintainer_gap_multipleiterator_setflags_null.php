<?php
/**
 * Maintainer gap: MultipleIterator::setFlags(null).
 * Zend: E_DEPRECATED parameter #1 ($flags) + soft-coerce to 0
 * VM: E_DEPRECATED parameter #0 ($flags) (wrong user arg index)
 */
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
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
