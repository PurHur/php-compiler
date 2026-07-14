<?php

declare(strict_types=1);

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
echo "ok\n";
