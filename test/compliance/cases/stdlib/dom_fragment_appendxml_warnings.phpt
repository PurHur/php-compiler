--TEST--
DOMDocumentFragment::appendXML() invalid XML libxml warnings (#16162, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$frag = $doc->createDocumentFragment();
$ok = $frag->appendXML('<a/><b/>');
echo ($ok ? 'valid-true' : 'valid-false'), "\n";
echo $frag->childNodes->length, "\n";

$frag2 = $doc->createDocumentFragment();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$bad = $frag2->appendXML('<unclosed');
restore_error_handler();
echo ($bad ? 'invalid-true' : 'invalid-false'), "\n";
echo count(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'DOMDocumentFragment::appendXML(): Entity: line 1: parser error :'))), "\n";
echo count(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'DOMDocumentFragment::appendXML(): <unclosed'))), "\n";
echo count(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'DOMDocumentFragment::appendXML():') && str_ends_with($w, '^'))), "\n";
--EXPECT--
valid-true
2
invalid-false
1
1
1
