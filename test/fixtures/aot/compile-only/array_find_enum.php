<?php
// Compile-only (#5638): array_find() closure callback on enum array must lower through AOT.
enum E: int { case A = 1; case B = 2; }
array_find([E::A, E::B], fn ($v) => $v === E::B);
