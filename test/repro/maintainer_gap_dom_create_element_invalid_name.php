<?php
/**
 * #20594 — createElement/createAttribute Invalid Character; create*NS Namespace Error
 * php-src: ext/dom/document.c (xmlValidateName / QName + namespace URI rules)
 */
$doc = new DOMDocument();
$doc->loadXML('<r/>');

function expect_dom(string $label, int $code, callable $fn): void
{
    try {
        $fn();
        echo $label, "=NO_THROW\n";
    } catch (DOMException $e) {
        echo $label, '=', $e->getMessage(), ' code=', $e->getCode(),
            ($e->getCode() === $code ? " OK\n" : " WANT_$code\n");
    }
}

expect_dom('ce_null', 5, fn () => $doc->createElement(null));
expect_dom('ce_empty', 5, fn () => $doc->createElement(''));
expect_dom('ce_space', 5, fn () => $doc->createElement(' '));
expect_dom('ce_digit', 5, fn () => $doc->createElement('1bad'));
expect_dom('ce_dash', 5, fn () => $doc->createElement('-x'));
echo 'ce_ok=', $doc->createElement('ok')->tagName, "\n";
echo 'ce_colon=', $doc->createElement(':bad')->tagName, "\n";

expect_dom('ca_empty', 5, fn () => $doc->createAttribute(''));
expect_dom('ca_digit', 5, fn () => $doc->createAttribute('1bad'));
echo 'ca_ok=', $doc->createAttribute('ok')->name, "\n";
echo 'ca_colon=', $doc->createAttribute(':bad')->name, "\n";

expect_dom('cens_empty', 14, fn () => $doc->createElementNS('urn:x', ''));
expect_dom('cens_digit', 14, fn () => $doc->createElementNS('urn:x', '1bad'));
expect_dom('cens_colon', 14, fn () => $doc->createElementNS('urn:x', ':bad'));
expect_dom('cens_dbl', 14, fn () => $doc->createElementNS('urn:x', 'a::b'));
expect_dom('cens_prefix_null', 14, fn () => $doc->createElementNS(null, 'p:a'));
expect_dom('cens_xml_bad', 14, fn () => $doc->createElementNS('urn:x', 'xml:a'));
echo 'cens_ok=', $doc->createElementNS('urn:x', 'p:a')->tagName, "\n";
echo 'cens_xmlns_unprefixed=', $doc->createElementNS('urn:x', 'xmlns')->tagName, "\n";

expect_dom('cans_empty', 14, fn () => $doc->createAttributeNS('urn:x', ''));
expect_dom('cans_digit', 14, fn () => $doc->createAttributeNS('urn:x', '1bad'));
expect_dom('cans_xmlns_prefix_bad', 14, fn () => $doc->createAttributeNS('urn:x', 'xmlns:x'));
echo 'cans_ok=', $doc->createAttributeNS('urn:x', 'p:a')->name, "\n";
echo 'cans_xmlns_null=', $doc->createAttributeNS(null, 'xmlns')->name, "\n";
echo 'cans_xmlns_wrong_uri=', $doc->createAttributeNS('urn:x', 'xmlns')->name, "\n";
