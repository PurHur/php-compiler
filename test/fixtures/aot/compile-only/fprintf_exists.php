<?php

declare(strict_types=1);

// Compile-only (#3301): fprintf() builtin registration must compile through AOT.
echo function_exists('fprintf') ? "yes\n" : "no\n";
