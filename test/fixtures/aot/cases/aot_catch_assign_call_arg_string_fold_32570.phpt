--TEST--
Language: AOT catch-assigned string must not compile-time fold to try-path empty literal (#32570)
--FILE--
<?php
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
--EXPECT--
21|Invalid email address
