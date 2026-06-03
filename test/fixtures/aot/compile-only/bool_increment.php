<?php
// Compile-only (#3552, #4727); bool ++ promotes to int(1).
$b = true;
$b++;
echo ($b === 1) ? "1\n" : "0\n";
