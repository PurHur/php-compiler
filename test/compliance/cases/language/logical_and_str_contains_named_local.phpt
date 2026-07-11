--TEST--
language logical && str_contains on named local — haystack survives (#15183)
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
