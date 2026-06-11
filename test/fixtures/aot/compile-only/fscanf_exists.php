<?php

declare(strict_types=1);

// Compile-only (#3284): fscanf() builtin registration must compile through AOT.
echo function_exists('fscanf') ? "yes\n" : "no\n";
