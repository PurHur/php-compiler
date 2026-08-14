<?php

declare(strict_types=1);

function check_excess(): void
{
    try {
        preg_replace_callback_array(['/a/' => fn ($m) => 'b'], 'a', -1, $c, 0, 1);
        echo "excess:OK\n";
    } catch (Throwable $e) {
        echo 'excess:', get_class($e), ':', $e->getMessage(), "\n";
    }
}

function check_ok(): void
{
    try {
        preg_replace_callback_array(['/a/' => fn ($m) => 'b'], 'a', -1, $count, 0);
        echo "ok:1\n";
    } catch (Throwable $e) {
        echo 'ok:', get_class($e), ':', $e->getMessage(), "\n";
    }
}

check_excess();
check_ok();
