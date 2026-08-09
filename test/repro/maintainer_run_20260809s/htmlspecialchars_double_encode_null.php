<?php
// #29445 — htmlspecialchars(..., null) $double_encode: Zend DEP+coerce, not LogicException.
error_reporting(E_ALL);
try {
    var_export(htmlspecialchars('a', ENT_QUOTES, 'UTF-8', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
