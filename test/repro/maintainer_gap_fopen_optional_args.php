<?php

declare(strict_types=1);

$h1 = fopen('php://memory', 'r+', false);
echo 'fopen_optional_ok=', is_resource($h1) ? '1' : '0', "\n";
if (is_resource($h1)) {
    fclose($h1);
}

$ctx = stream_context_create([]);
$h2 = fopen('php://memory', 'r+', false, $ctx);
echo 'fopen_context_ok=', is_resource($h2) ? '1' : '0', "\n";
if (is_resource($h2)) {
    fclose($h2);
}
