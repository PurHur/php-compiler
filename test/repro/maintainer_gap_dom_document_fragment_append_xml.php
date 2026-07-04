<?php

declare(strict_types=1);

$doc = new DOMDocument();
$frag = $doc->createDocumentFragment();
$ok = $frag->appendXML('<a/><b/>');
if (true !== $ok) {
    echo 'fail: appendXML returned ', var_export($ok, true), "\n";
    exit(1);
}
if (2 !== $frag->childNodes->length) {
    echo 'fail: child count ', $frag->childNodes->length, "\n";
    exit(1);
}
if ('a' !== $frag->childNodes->item(0)->nodeName || 'b' !== $frag->childNodes->item(1)->nodeName) {
    echo 'fail: children ', $frag->childNodes->item(0)->nodeName, ',', $frag->childNodes->item(1)->nodeName, "\n";
    exit(1);
}

$frag2 = $doc->createDocumentFragment();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$bad = $frag2->appendXML('<unclosed');
restore_error_handler();
if (false !== $bad) {
    echo 'fail: invalid xml should return false', "\n";
    exit(1);
}
$hasPrefix = false;
foreach ($warnings as $warning) {
    if (str_contains($warning, 'DOMDocumentFragment::appendXML(): Entity: line 1: parser error :')) {
        $hasPrefix = true;
        break;
    }
}
if (!$hasPrefix) {
    echo 'fail: missing DOMDocumentFragment::appendXML() warning prefix', "\n";
    exit(1);
}

echo "ok\n";
