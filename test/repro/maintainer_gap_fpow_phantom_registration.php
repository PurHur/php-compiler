<?php

declare(strict_types=1);

if (function_exists('fpow')) {
    echo "fail: fpow registered on reference profile\n";
    exit(1);
}

echo "ok\n";
