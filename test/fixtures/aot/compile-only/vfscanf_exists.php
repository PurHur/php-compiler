<?php

declare(strict_types=1);

// Compile-only (#26758): vfscanf() must stay unregistered (php-src has sscanf/fscanf only).
echo function_exists('vfscanf') ? "yes\n" : "no\n";
echo function_exists('fscanf') ? "fscanf-yes\n" : "fscanf-no\n";
