<?php
// Compile-only (#4584): count() COUNT_RECURSIVE LLVM lowering.
$a = array(1, array(2, array(3, 4)));
echo count($a, COUNT_RECURSIVE), "\n";
