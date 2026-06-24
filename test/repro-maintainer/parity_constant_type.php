<?php

/** Issue #4846 — constant() non-string name must throw TypeError (ext/standard/basic_functions.c). */

try {
    constant(1);
} catch (Error $e) {
    echo "Error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
