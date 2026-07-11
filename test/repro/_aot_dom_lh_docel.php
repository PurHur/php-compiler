<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML('<p id="target">hello</p>');
$root = $doc->documentElement;
echo null !== $root ? 'has_root' : 'no_root', "\n";
