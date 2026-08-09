<?php
// Repro #29354 — wordwrap(..., null) cut_long_words: Zend DEP+coerce to false (ext/standard/string.c)
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo 'ERR[8192]: ', $msg, "\n";

        return true;
    }

    return false;
});
try {
    var_export(wordwrap('abcd', 2, "\n", null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
