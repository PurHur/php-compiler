<?php
/**
 * Maintainer gap: LimitIterator null offset/limit — soft-null DEP + OOB (#31621).
 * php-src: ext/spl/spl_iterators.c — zim_LimitIterator___construct / Z_PARAM_LONG
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

try {
    $li = new LimitIterator(new ArrayIterator([1, 2, 3]), null, null);
    echo 'ok:' . json_encode(iterator_to_array($li)) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
