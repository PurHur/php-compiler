<?php
// Repro #29720 — wordwrap(..., null) $break: Zend DEP+coerce then empty ValueError
ini_set('error_reporting', (string) E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEP:', $msg, "\n";

        return true;
    }

    return false;
});
try {
    var_export(wordwrap('hi there', 75, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
