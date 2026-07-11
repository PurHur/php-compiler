<?php

declare(strict_types=1);

$doc = new DOMDocument();
$found = $doc->getElementById('target');
echo null !== $found ? 'found' : 'not_found', "\n";
