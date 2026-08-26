<?php

declare(strict_types=1);

/**
 * Minimal #17158 / 007-ThrowsWeb AOT: user Exception subclass catch must assign in handler.
 * php-src: Zend/zend_exceptions.c zend_throw_exception / zend_catch
 */
class ValidationError extends Exception
{
}

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
$error = '';

if ('POST' === $method) {
    try {
        throw new ValidationError();
    } catch (ValidationError $e) {
        $error = 'Invalid email address';
    }
}

echo 'method=', $method, "\n";
echo 'error=[', $error, "]\n";
echo 'len=', strlen($error), "\n";
