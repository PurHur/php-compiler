<?php
declare(strict_types=1);
try { proc_terminate(); } catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
