<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->createElement('a');
$doc->loadHTML('<p>hi</p>');
$found = $doc->getElementById('x');
echo "ok\n";
