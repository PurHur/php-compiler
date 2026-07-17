--TEST--
DOMDocument::relaxNGValidateSource() — valid in-memory RNG returns true; invalid fills libxml_get_errors (#20235, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root><child/></root>');
$rng = '<?xml version="1.0"?><grammar xmlns="http://relaxng.org/ns/structure/1.0">'
    . '<start><element name="root"><zeroOrMore><element name="child"><empty/></element></zeroOrMore></element></start>'
    . '</grammar>';
libxml_use_internal_errors(true);
libxml_clear_errors();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$ok = $doc->relaxNGValidateSource($rng);
restore_error_handler();
var_export($ok);
echo "\n";
echo count(libxml_get_errors()), "\n";
echo count($warnings), "\n";

$badDoc = new DOMDocument();
$badDoc->loadXML('<root><other/></root>');
libxml_clear_errors();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$bad = $badDoc->relaxNGValidateSource($rng);
restore_error_handler();
var_export($bad);
echo "\n";
echo count($warnings), "\n";
$errs = libxml_get_errors();
echo count($errs), "\n";
if (isset($errs[0])) {
    echo trim($errs[0]->message), "\n";
    echo (int) $errs[0]->level, "\n";
}
?>
--EXPECT--
true
0
0
false
0
1
Did not expect element other there
2
