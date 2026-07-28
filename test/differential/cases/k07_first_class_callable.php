<?php
// FAILS ON AOT — #24166. First-class callable syntax (PHP 8.1).
//
// Bounding evidence, two distinct failures depending on the callee:
//     dbl(...)     user function -> COMPILER crash (the shape below)
//     strlen(...)  builtin       -> compiles, then core dumps at runtime
//     strlen("hi") direct call   -> correct (control passes 3/3)
// So it is the (...) form, not the callee. The user-function shape is used here because a compile
// failure is a cleaner corpus line than a core dump.
//
// Diagnosed root cause of the user-function message, recorded on the issue: lib/JIT/
// VariableFunctionCallRuntime.php:150 says `new JIT($context)` inside namespace PHPCompiler\JIT,
// resolving to PHPCompiler\JIT\JIT; the class is PHPCompiler\JIT. Fixing that alone is NOT enough —
// it exposes a segfault in the compiler behind it, so both must land together.
function dbl(int $n): int { return $n * 2; }
$f = dbl(...);
echo $f(21), "\n";
