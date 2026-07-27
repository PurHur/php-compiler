<?php

$doc = new DOMDocument();
@$doc->loadHTML('<p>&eacute;x&nbsp;y</p>');
$html = $doc->saveHTML();

$checks = [
    'eacute' => str_contains($html, '&eacute;') ? 'entity' : 'other',
    'nbsp'   => str_contains($html, '&nbsp;')   ? 'entity' : 'other',
];

foreach ($checks as $name => $result) {
    echo "$name=$result\n";
}

echo 'textContent=' . bin2hex($doc->getElementsByTagName('p')->item(0)->textContent) . "\n";
echo "html=" . trim($html) . "\n";
