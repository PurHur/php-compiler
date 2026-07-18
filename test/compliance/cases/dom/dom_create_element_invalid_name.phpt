--TEST--
dom createElement/createAttribute invalid XML Name + create*NS Namespace Error (#20594)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r/>');

function expect_dom(string $label, int $code, callable $fn): void
{
    try {
        $fn();
        echo $label, "=NO_THROW\n";
    } catch (DOMException $e) {
        echo $label, '=', (int) ($e->getCode() === $code), "\n";
    }
}

expect_dom('ce_empty', 5, fn () => $doc->createElement(''));
expect_dom('ce_digit', 5, fn () => $doc->createElement('1bad'));
expect_dom('ca_empty', 5, fn () => $doc->createAttribute(''));
expect_dom('cens_digit', 14, fn () => $doc->createElementNS('urn:x', '1bad'));
expect_dom('cens_prefix_null', 14, fn () => $doc->createElementNS(null, 'p:a'));
expect_dom('cans_xmlns_prefix_bad', 14, fn () => $doc->createAttributeNS('urn:x', 'xmlns:x'));
echo $doc->createElement('ok')->tagName, "\n";
echo $doc->createElementNS('urn:x', 'p:a')->tagName, "\n";
echo $doc->createAttributeNS(null, 'xmlns')->name, "\n";
echo $doc->createAttribute(':bad')->name, "\n";
--EXPECT--
ce_empty=1
ce_digit=1
ca_empty=1
cens_digit=1
cens_prefix_null=1
cans_xmlns_prefix_bad=1
ok
p:a
xmlns
:bad
