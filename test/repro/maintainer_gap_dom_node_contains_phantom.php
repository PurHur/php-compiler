<?php

declare(strict_types=1);

// Forward profile (8.4.0-dev) advertises contains(); reference profile must not (#14535).
if (0 === strpos(PHP_VERSION, '8.4')) {
    echo "skip_forward_profile\n";
    exit(0);
}

if (method_exists(DOMNode::class, 'contains')) {
    fwrite(STDERR, "fail: contains exposed on 8.2 profile\n");
    exit(1);
}

echo "ok_no_method\n";
