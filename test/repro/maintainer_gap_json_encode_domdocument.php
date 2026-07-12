<?php
declare(strict_types=1);

// json_encode(DOMDocument) must yield '{}' not false (php-src ext/dom; #18292).
$dom = new DOMDocument();
$dom->loadHTML('<p>hi</p>');
$encoded = json_encode($dom);
echo $encoded, "\n";
var_export($encoded);
echo "\n";
echo json_last_error() === 0 ? '0' : (string) json_last_error(), "\n";
