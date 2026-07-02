<?php

// Maintainer gap #14879 — foreach list() destructuring mix keyed/unkeyed.
foreach ([[1, 2]] as list(0 => $x, $y)) {
    echo $x . $y;
}
