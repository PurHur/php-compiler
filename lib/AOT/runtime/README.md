# AOT runtime C floor (empty)

Hand-written C translation units for the AOT link step lived here during the
php-in-PHP migration ([#1492](https://github.com/PurHur/php-compiler/issues/1492)).

As of [#17148](https://github.com/PurHur/php-compiler/issues/17148) / [#17149](https://github.com/PurHur/php-compiler/pull/17149), the last bundled TU
(`phpc_progress.c`) was replaced by LLVM emitted from
`lib/JIT/Builtin/ProgressNoteRuntime.php`. This directory is intentionally kept
**empty of `*.c` files** so inventory guards (`AotRuntimeInventoryTest`,
`RuntimeShrinkCloseoutTest`) can assert zero hand-written C runtime sources remain.

New compiler/runtime semantics belong in `lib/`, `lib/JIT/`, `ext/`, and thin
ABI bridges — not here.
