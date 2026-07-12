<?php

declare(strict_types=1);

$x = 1;
try {
    echo $x::class;
} catch (TypeError $e) {
    echo "typeerror\n";
}
