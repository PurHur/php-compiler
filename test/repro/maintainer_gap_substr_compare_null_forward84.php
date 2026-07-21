<?php
// #21515 — substr_compare(null) soft-null DEP+coerce on PHP_COMPILER_PROFILE=8.4 (reverts #20164)
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
try {
    echo 'haystack:'.substr_compare(null, 'a', 0)."\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage()."\n";
}
try {
    echo 'needle:'.substr_compare('a', null, 0)."\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage()."\n";
}
