<?php
declare(strict_types=1);
try { proc_close(); } catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
