<?php
try {
    proc_nice();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
