<?php
// Repro #30864 — Enum::cases/from/tryFrom excess/missing argc → ArgumentCountError
enum E: int { case A = 1; }
try {
    var_export(E::cases(1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(E::from(1, 2));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(E::tryFrom(99, 2));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(E::from());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(E::tryFrom());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
// Happy-path scalars only — avoid var_export/count on enum lists under thin AOT (#26855).
E::cases();
echo 'ok=', E::from(1)->name, ',', E::from(1)->value, ',', E::tryFrom(99) === null ? 'NULL' : 'x', "\n";
