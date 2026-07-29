<?php
/**
 * #24836 — AOT must not SIGSEGV on static arrow / static closure.
 *
 * Root cause: ClosureBindHelper::storeStaticClosureFlag() called builder->load()
 * on an i1 constInt, treating a boolean constant as a pointer. LLVM then crashed
 * inside Value::setName (seen building ext/spl GlobIterator under SPINE_CHUNK,
 * which uses `static fn` in array_filter).
 *
 * Expect: prints ok / false / 1
 */
$staticArrow = static fn (string $p): bool => $p !== '.';
$staticClosure = static function ($p) {
    return $p;
};

echo 'ok', "\n";
var_export($staticArrow('.'));
echo "\n";
echo $staticClosure(1), "\n";
