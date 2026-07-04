--TEST--
language logical && extension_loaded guard — named haystack survives (#16040)
--FILE--
<?php
declare(strict_types=1);

$haystack = 'hello world';
if (!extension_loaded('curl') && str_contains($haystack, 'cURL')) {
}
echo gettype($haystack), "\n";
?>
--EXPECT--
string
