<?php
// Compile-only (#3552); native AOT ++/-- on bool mismatches VM until MCJIT parity.
$b = true;
$b++;
echo (true === $b) ? "1\n" : "0\n";
