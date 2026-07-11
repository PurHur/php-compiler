<?php

declare(strict_types=1);

try {
    user_error('fatal test', E_USER_ERROR);
    echo "uncaught_path\n";
} catch (Throwable $e) {
    echo 'caught=', get_class($e), "\n";
}
echo "after\n";
