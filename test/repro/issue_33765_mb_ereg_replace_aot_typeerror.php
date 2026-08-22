<?php

declare(strict_types=1);

// #33765 — AOT must not LogicException on argc/TypeError gates (peer mb_eregi_replace #33656).
try {
    mb_ereg_replace();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
try {
    mb_ereg_replace(null, "b", "c");
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
