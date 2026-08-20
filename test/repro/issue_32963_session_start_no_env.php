<?php

declare(strict_types=1);

// AOT must not SIGSEGV when PHP_COMPILER_SESSION_DIR is unset (#32963).
session_start();
echo "ok\n";
