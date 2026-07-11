<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML('<p id="target">hello</p>');
echo $doc->saveHTML();
