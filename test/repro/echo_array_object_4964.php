<?php
// Maintainer repro for #4964 — echo array/object on JIT (with MCJIT embed bootstrap class).
class EchoArrayJitBootstrap {
    public function __toString(): string { return ''; }
}
echo [1, 2];
echo "\n";
class C {}
try {
    echo new C();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
