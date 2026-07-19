<?php
// Repro #20782 — Dom\AdjacentPosition enum + living insertAdjacent* (php-src php_dom.stub.php)
echo 'enum: ', enum_exists('Dom\\AdjacentPosition') ? 'yes' : 'no', "\n";
if (!enum_exists('Dom\\AdjacentPosition')) {
    exit(0);
}
foreach (Dom\AdjacentPosition::cases() as $c) {
    echo $c->name, '=', $c->value, "\n";
}

$d = Dom\HTMLDocument::createEmpty();
$html = $d->createElement('html');
$body = $d->createElement('body');
$d->append($html);
$html->append($body);
$p = $d->createElement('p');
$body->append($p);
$n = $d->createElement('i');
$p->insertAdjacentElement(Dom\AdjacentPosition::AfterBegin, $n);
echo 'living-enum: ', $p->firstElementChild !== null ? 'ok' : 'bad', "\n";

try {
    $p->insertAdjacentElement('beforeend', $d->createElement('b'));
    echo "living-string: ok\n";
} catch (Throwable $e) {
    echo 'living-string: ', get_class($e), "\n";
}

$legacy = new DOMDocument();
$legacy->loadXML('<root><a/></root>');
$el = $legacy->documentElement;
try {
    $el->insertAdjacentElement(Dom\AdjacentPosition::BeforeBegin, $legacy->createElement('i'));
    echo "legacy-enum: ok\n";
} catch (Throwable $e) {
    echo 'legacy-enum: ', get_class($e), "\n";
}
$el->insertAdjacentElement('beforeend', $legacy->createElement('x'));
echo "legacy-string: ok\n";
