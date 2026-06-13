<?php
// Compile-only: str_contains family scalar int coercion for AOT (ext/standard/string.c).
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn(1, 'a');
        echo "{$fn}: no throw\n";
    } catch (TypeError $e) {
        echo "{$fn}: ", $e->getMessage(), "\n";
    }
}
