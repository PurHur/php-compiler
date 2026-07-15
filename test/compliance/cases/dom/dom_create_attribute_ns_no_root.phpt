--TEST--
DOMDocument::createAttributeNS() without root returns false (#19200, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$attr = $doc->createAttributeNS('http://example.com', 'p:a');
restore_error_handler();
echo var_export($attr, true), "\n";
echo count($warnings), "\n";
echo $warnings[0] ?? '', "\n";
$doc->loadXML('<root/>');
$attr = $doc->createAttributeNS('http://example.com', 'ex:foo');
echo get_class($attr), "\n";
echo $attr->nodeName, "\n";
?>
--EXPECT--
false
1
DOMDocument::createAttributeNS(): Document Missing Root Element
DOMAttr
ex:foo
