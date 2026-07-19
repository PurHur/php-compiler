<?php
// Repro #21180 — HTML/escape soft-null under PHP_COMPILER_PROFILE=8.4 (Zend DEP+coerce)
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach (['htmlspecialchars','htmlentities','addslashes','stripslashes','nl2br','quotemeta'] as $f) {
    try {
        $r = $f(null);
        echo $f, " OK\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
