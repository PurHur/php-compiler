<?php

declare(strict_types=1);

if (class_exists('NoDiscard', false)) {
    echo "fail: NoDiscard phantom class on 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";
