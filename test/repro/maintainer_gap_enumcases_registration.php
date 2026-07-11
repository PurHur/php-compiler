<?php

declare(strict_types=1);

if (class_exists('EnumCases', false)) {
    echo "fail: EnumCases phantom class on 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";
