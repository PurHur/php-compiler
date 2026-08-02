<?php
/**
 * Repro for #26786 — BackedEnum::from/tryFrom(null) must emit Zend E_DEPRECATED
 * before weak null→0/"0" coerce (Zend/zend_enum.c).
 */
error_reporting(E_ALL);
set_error_handler(function (int $no, string $msg): bool {
    echo 'DEP:', $msg, "\n";

    return true;
});
enum E: string { case A = 'a'; }
enum I: int { case A = 1; }
echo 'tryFrom_str=', var_export(E::tryFrom(null), true), "\n";
echo 'tryFrom_int=', var_export(I::tryFrom(null), true), "\n";
try {
    E::from(null);
} catch (Throwable $e) {
    echo 'from_str=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    I::from(null);
} catch (Throwable $e) {
    echo 'from_int=', get_class($e), ':', $e->getMessage(), "\n";
}
