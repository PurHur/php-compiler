<?php

declare(strict_types=1);

// #33656 — AOT must not LogicException on argc/TypeError gates (peer mb_ereg #33648 / #30311).
try {
    mb_eregi_replace();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
try {
    mb_eregi_replace(null, "b", "c");
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
