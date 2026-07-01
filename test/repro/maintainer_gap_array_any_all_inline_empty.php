<?php
// Issue #14516 — array_any/array_all inline [] in expression position.
$cb = static fn ($v) => (bool) $v;
try {
    array_any([], $cb);
    echo "stmt_any=false\n";
} catch (Throwable $e) {
    echo 'stmt_any_err='.$e->getMessage()."\n";
}
try {
    var_export(array_any([], $cb));
    echo "\nexpr_any=false\n";
} catch (Throwable $e) {
    echo 'expr_any_err='.$e->getMessage()."\n";
}
try {
    var_export(array_all([], $cb));
    echo "\nexpr_all=true\n";
} catch (Throwable $e) {
    echo 'expr_all_err='.$e->getMessage()."\n";
}
