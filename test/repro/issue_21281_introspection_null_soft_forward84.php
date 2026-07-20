<?php
// Repro #21281 — introspection builtins soft-null under PROFILE=8.4 (Zend DEP+coerce)
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cases = [
    ['function_exists', [null]],
    ['class_exists', [null]],
    ['interface_exists', [null]],
    ['trait_exists', [null]],
    ['enum_exists', [null]],
    ['extension_loaded', [null]],
    ['defined', [null]],
];
foreach ($cases as [$f, $a]) {
    try {
        $r = $f(...$a);
        echo $f, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
try {
    $r = constant(null);
    echo 'constant OK ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'constant ', get_class($e), ': ', $e->getMessage(), "\n";
}
