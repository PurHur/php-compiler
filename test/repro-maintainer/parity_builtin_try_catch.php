<?php

declare(strict_types=1);

// Issue #4866 — builtin exceptions must honor user try/catch (zend_exceptions.c).

try {
    substr_compare('a', 'b', 0, -1);
    echo "no throw\n";
} catch (ValueError $e) {
    echo "caught ValueError\n";
}

try {
    floor(new stdClass());
    echo "no throw\n";
} catch (TypeError $e) {
    echo "caught TypeError\n";
}
