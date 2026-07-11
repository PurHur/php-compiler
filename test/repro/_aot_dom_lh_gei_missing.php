<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML('<p id="target">hello</p>');
$missing = $doc->getElementById('missing');
echo null === $missing ? 'missing_null' : 'missing_found', "\n";
