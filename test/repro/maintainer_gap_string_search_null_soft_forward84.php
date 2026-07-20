<?php
// Repro #21444 — stripos/strripos/strrpos/stristr/strchr/strrchr/strpbrk haystack null soft-null on 8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
    }

    return true;
});
foreach (['stripos', 'strripos', 'strrpos', 'stristr', 'strchr', 'strrchr', 'strpbrk'] as $f) {
    try {
        $r = 'strpbrk' === $f ? strpbrk(null, 'abc') : $f(null, 'a');
        echo $f, ' ', ($r === false ? 'OK' : 'BAD'), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
