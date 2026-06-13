<?php
// Compile-only (#5018): str_contains family strict Z_PARAM_STR int TypeError lowering for AOT.
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn(1, 'a');
        echo "{$fn}: no throw\n";
    } catch (TypeError $e) {
        echo "{$fn}: ", $e->getMessage(), "\n";
    }
}
