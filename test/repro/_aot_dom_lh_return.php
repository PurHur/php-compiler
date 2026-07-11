<?php

declare(strict_types=1);

$doc = new DOMDocument();
$ok = $doc->loadHTML('<p id="target">hello</p>');
echo $ok ? 'loadhtml_ok' : 'loadhtml_fail', "\n";
