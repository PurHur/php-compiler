<?php

declare(strict_types=1);

$doc = new DOMDocument();
$found = $doc->getElementById('target');
echo null === $found ? 'gei_null' : 'gei_found', "\n";
