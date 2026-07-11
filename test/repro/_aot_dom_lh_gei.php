<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML('<p id="target">hello</p>');
$found = $doc->getElementById('target');
echo null !== $found ? 'found' : 'not_found', "\n";
