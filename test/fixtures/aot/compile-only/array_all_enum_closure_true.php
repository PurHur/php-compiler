<?php
// Compile-only (#9471): array_all() closure on enum array must lower without LLVM parent-block crash.
enum E: int { case A = 1; case B = 2; }
array_all([E::A, E::B], fn ($v) => true);
