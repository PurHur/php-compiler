<?php
/**
 * #21655 — preg_replace/preg_split/preg_filter null $limit soft-null under PROFILE=8.4.
 * Zend: E_DEPRECATED + limit 0 semantics (unchanged / full split / null).
 */
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
try {
    var_export(preg_replace('/\w/', 'x', 'ab', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(count(preg_split('//u', 'ab', null)));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(preg_filter('/\w/', 'x', 'ab', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
