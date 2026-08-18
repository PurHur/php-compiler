<?php
try {
    include __DIR__ . '/bad.php';
    echo "after";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
