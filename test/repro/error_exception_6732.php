<?php

var_export(class_exists('ErrorException'));
echo "\n";
try {
    $e = new ErrorException('m', 0, E_USER_WARNING, __FILE__, 42);
    echo $e->getSeverity(), "\n";
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
}
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    trigger_error('probe', E_USER_WARNING);
} catch (ErrorException $e) {
    echo $e->getMessage(), ':', $e->getSeverity(), "\n";
}
