<?php
// #30711 — filter_has_var / filter_input excess argc → ArgumentCountError (Zend wording).
try {
    filter_has_var(INPUT_GET, 'x', 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    filter_input(INPUT_GET, 'x', FILTER_DEFAULT, 0, 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
