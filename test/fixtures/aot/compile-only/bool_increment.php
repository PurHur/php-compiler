<?php
// Compile-only (#3552, #7058); bool ++ is a no-op preserving bool.
$b = true;
$b++;
echo ($b === true) ? "true\n" : "false\n";
