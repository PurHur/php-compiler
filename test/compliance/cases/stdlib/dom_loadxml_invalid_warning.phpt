--TEST--
DOMDocument::loadXML() invalid XML libxml warning prefix (#16192, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$ok = $doc->loadXML('<unclosed');
restore_error_handler();
echo ($ok ? 'load-true' : 'load-false'), "\n";
echo count(array_filter(
    $warnings,
    static fn (string $w): bool => str_contains($w, "DOMDocument::loadXML(): Couldn't find end of Start Tag unclosed line 1 in Entity, line: 1")
)), "\n";
--EXPECT--
load-false
1
