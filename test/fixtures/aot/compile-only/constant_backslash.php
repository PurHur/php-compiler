<?php
// Compile-only (#12190): constant() leading-backslash normalization for AOT/JIT.
define('GAP_CONST', 42);
echo constant('\\GAP_CONST'), "\n";
