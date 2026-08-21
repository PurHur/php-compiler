<?php

declare(strict_types=1);

// #33655 — AOT must not LogicException on argc/TypeError gates (peer mb_ereg #33648).
try {
    mb_ereg_match();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
try {
    mb_ereg_match(null, "x");
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
