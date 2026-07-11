<?php

declare(strict_types=1);

if (function_exists('class_constants')) {
    echo "fail: class_constants registered on reference profile\n";
    exit(1);
}

echo "ok\n";
