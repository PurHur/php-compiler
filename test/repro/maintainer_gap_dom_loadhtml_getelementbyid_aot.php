<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML('<p id="target">hello</p>');
$found = $doc->getElementById('target');
echo null !== $found ? $found->textContent : 'null', "\n";
echo null === $doc->getElementById('missing') ? 'missing_null' : 'missing_found', "\n";
