<?php

declare(strict_types=1);

if (0 !== ignore_user_abort(0)) {
    echo "fail: ignore_user_abort(0) return\n";
    exit(1);
}

if (0 !== ignore_user_abort(1)) {
    echo "fail: ignore_user_abort(1) return\n";
    exit(1);
}

if (1 !== ignore_user_abort(false)) {
    echo "fail: ignore_user_abort(false) return\n";
    exit(1);
}

if (0 !== ignore_user_abort(null)) {
    echo "fail: ignore_user_abort(null) return\n";
    exit(1);
}

echo "ok\n";
