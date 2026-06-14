<?php
// Compile-only (#5722): array_all()/array_any() closure callbacks on enum arrays must lower through AOT.
enum E: int { case A = 1; case B = 2; }
array_all([E::A, E::B], fn ($v) => $v instanceof E);
array_any([E::A, E::B], fn ($v) => $v === E::A);
