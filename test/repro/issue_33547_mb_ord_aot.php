<?php

declare(strict_types=1);

// #33547 leftover of #30759 — AOT must not LogicException on argc/TypeError gates
try {
    mb_ord();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
try {
    mb_ord([]);
    echo "type-ok\n";
} catch (TypeError $e) {
    echo "type\n";
}
