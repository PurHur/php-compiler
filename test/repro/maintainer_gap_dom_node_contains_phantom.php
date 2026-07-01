<?php

declare(strict_types=1);

if (method_exists(DOMNode::class, 'contains')) {
    fwrite(STDERR, "fail: contains exposed on 8.2 profile\n");
    exit(1);
}

echo "ok_no_method\n";
