<?php

declare(strict_types=1);

// #33648 — AOT must not LogicException on argc/TypeError gates (peer mb_ord #33547 order).
try {
    mb_eregi();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
try {
    mb_eregi(null, "x");
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
