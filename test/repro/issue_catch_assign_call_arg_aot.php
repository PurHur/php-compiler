<?php

declare(strict_types=1);

/**
 * Repro #32570 — catch-assigned locals must reach builtins at runtime, not as try-path literals.
 */
class ValidationError extends Exception
{
}

$error = '';
try {
    throw new ValidationError();
} catch (ValidationError $e) {
    $error = 'Invalid email address';
}

echo strlen($error), '|', htmlspecialchars($error), "\n";
