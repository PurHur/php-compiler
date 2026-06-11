<?php

declare(strict_types=1);

// Compile-only (#6174): vfscanf() builtin registration must compile through AOT.
echo function_exists('vfscanf') ? "yes\n" : "no\n";
