<?php
/**
 * Repro for #16128 — DOMElement::insertAdjacentHTML() on PHP 8.4 profile.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_dom_insert_adjacent_html.php
 *
 * @see ext/dom/dom_element.c (php-src)
 */
$doc = new DOMDocument();
$el = $doc->createElement('div');
$doc->appendChild($el);
echo method_exists(DOMElement::class, 'insertAdjacentHTML') ? "exists\n" : "missing\n";
$el->insertAdjacentHTML('afterbegin', '<b>x</b>');
echo preg_replace('/\s+/', '', $doc->saveHTML($el)), "\n";
$el->insertAdjacentHTML('beforeend', '<i>y</i>');
echo preg_replace('/\s+/', '', $doc->saveHTML($el)), "\n";
$sib = $doc->createElement('sib');
$el->parentNode->appendChild($sib);
$el->insertAdjacentHTML('afterend', '<em>z</em>');
echo preg_replace('/\s+/', '', $doc->saveHTML($doc->documentElement)), "\n";
try {
    $el->insertAdjacentHTML('invalid', 'x');
    echo "invalid: ok\n";
} catch (ValueError $e) {
    echo "invalid: ValueError\n";
} catch (Throwable $e) {
    echo 'invalid: ', get_class($e), "\n";
}
