<?php

declare(strict_types=1);

// AOT compile-only: spl_autoload() + spl_autoload_extensions() JIT lowering (#4256).
echo spl_autoload_extensions(), "\n";
spl_autoload_extensions('.probe');
echo spl_autoload_extensions(), "\n";
