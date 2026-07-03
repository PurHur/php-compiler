<?php

declare(strict_types=1);

$d = new DOMDocument();
$d->loadHTML('<?pi test?><html><body></body></html>');
$types = [];
foreach ($d->childNodes as $node) {
    $types[] = $node->nodeType;
}
if ($types !== [10, 7, 1]) {
    exit(1);
}
echo "ok\n";
