<?php

declare(strict_types=1);

echo 'exists=', var_export(function_exists('mb_str_pad'), true), "\n";
echo 'is_callable=', var_export(is_callable('mb_str_pad'), true), "\n";
try {
    echo 'call=', var_export(mb_str_pad('a', 3), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
