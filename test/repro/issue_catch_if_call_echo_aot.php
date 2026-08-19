<?php

declare(strict_types=1);

/**
 * Repro #32636 — catch-assigned locals inside if() must reach call args at runtime.
 * Leftover of #32570: divergent try/catch strings were not detected on if-arm blocks.
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

if ('' !== $error) {
    echo strlen($error), '|', htmlspecialchars($error), "\n";
}
