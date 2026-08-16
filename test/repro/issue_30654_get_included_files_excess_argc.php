<?php
foreach (['get_included_files', 'get_required_files'] as $f) {
    try {
        $f(1);
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
