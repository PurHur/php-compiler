--TEST--
DOMDocument::loadHTML() unclosed tag libxml warnings (#16190, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$ok = $doc->loadHTML('<p><unclosed');
restore_error_handler();
echo ($ok ? 'load-true' : 'load-false'), "\n";
echo count(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'DOMDocument::loadHTML(): Tag unclosed invalid in Entity, line: 1'))), "\n";
echo count(array_filter($warnings, static fn (string $w): bool => str_contains($w, "DOMDocument::loadHTML(): Couldn't find end of Start Tag unclosed in Entity, line: 1"))), "\n";

$doc2 = new DOMDocument();
$ok2 = $doc2->loadHTML('<p>hi</p>');
echo ($ok2 ? 'valid-true' : 'valid-false'), "\n";
echo $doc2->documentElement->nodeName, "\n";
--EXPECT--
load-true
1
1
valid-true
html
