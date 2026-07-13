<?php

declare(strict_types=1);

// #18600 — explode(',', null) must TypeError (ext/standard/string.c)
try {
    explode(',', null);
    echo "explode: no_ex\n";
} catch (TypeError $e) {
    echo "explode: TypeError\n";
}
