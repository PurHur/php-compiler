--TEST--
Language: AOT catch-assigned string in if body must reach htmlspecialchars (#32636, ThrowsWeb #2076)
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
if ('' !== $error) {
    echo '<p class="invalid">', htmlspecialchars($error), "</p>\n";
} else {
    echo "<p>Submit an email.</p>\n";
}
--EXPECT--
<p class="invalid">Invalid email address</p>
