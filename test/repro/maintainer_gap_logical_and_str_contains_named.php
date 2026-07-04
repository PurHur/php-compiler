<?php
declare(strict_types=1);

$haystack = 'hello world';
echo "plain before=" . gettype($haystack) . "\n";
if (!extension_loaded('curl') && str_contains($haystack, 'cURL')) {
}
echo "plain after=" . gettype($haystack) . "\n";
