<?php
// Compile-only (#8796): in_array()/array_search() with inline enum array literal args.
enum E: int { case A = 1; case B = 2; }

var_export(in_array(E::A, [E::A, E::B], true));
echo "\n";
var_export(array_search(E::A, [E::A, E::B]));
echo "\n";
