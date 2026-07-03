<?php

declare(strict_types=1);

$d = new DOMDocument();
$d->loadHTML('<!DOCTYPE html><html><body></body></html>');
$html = $d->saveHTML();
if (str_contains($html, 'PUBLIC')) {
    exit(1);
}
if (!str_starts_with($html, '<!DOCTYPE html>')) {
    exit(1);
}
echo "ok\n";
