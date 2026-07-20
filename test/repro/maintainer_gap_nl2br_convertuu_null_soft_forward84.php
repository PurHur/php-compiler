<?php
/**
 * #21420 — nl2br/convert_uuencode/convert_uudecode(null) DEP+coerce on PROFILE=8.4
 * (php-src ext/standard/string.c / uuencode.c; TypeError deferred to 9.0).
 */
error_reporting(E_ALL);
$deps = 0;
$warns = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps, &$warns): bool {
    if ($no === E_DEPRECATED) {
        ++$deps;
        return true;
    }
    if ($no === E_WARNING) {
        ++$warns;
        return true;
    }
    return false;
});

$ok = true;
try {
    if (nl2br(null) !== '') {
        echo "FAIL nl2br return\n";
        $ok = false;
    }
} catch (Throwable $e) {
    echo 'FAIL nl2br ', get_class($e), ':', $e->getMessage(), "\n";
    $ok = false;
}

try {
    if (convert_uuencode(null) !== "`\n") {
        echo "FAIL convert_uuencode return\n";
        $ok = false;
    }
} catch (Throwable $e) {
    echo 'FAIL convert_uuencode ', get_class($e), ':', $e->getMessage(), "\n";
    $ok = false;
}

try {
    if (convert_uudecode(null) !== false) {
        echo "FAIL convert_uudecode return\n";
        $ok = false;
    }
} catch (Throwable $e) {
    echo 'FAIL convert_uudecode ', get_class($e), ':', $e->getMessage(), "\n";
    $ok = false;
}

if ($deps < 3) {
    echo "FAIL expected >=3 deprecations, got {$deps}\n";
    $ok = false;
}
if ($warns < 1) {
    echo "FAIL expected convert_uudecode invalid warning, got {$warns}\n";
    $ok = false;
}

echo $ok ? "ALL_OK\n" : "FAIL\n";
exit($ok ? 0 : 1);
