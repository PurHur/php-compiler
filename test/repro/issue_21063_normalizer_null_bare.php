<?php

declare(strict_types=1);

// Issue #21063 — bare uncaught TypeError under PROFILE=8.4 (VM/JIT/AOT).
putenv('PHP_COMPILER_PROFILE=8.4');
normalizer_normalize(null);
echo "NO\n";
