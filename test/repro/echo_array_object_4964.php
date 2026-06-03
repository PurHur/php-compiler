<?php
// Maintainer repro for #4964 — echo array/object on JIT (file path; -r uses bin/jit.php embed bootstrap).
echo [1, 2];
echo "\n";
class C {}
try {
    echo new C();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
