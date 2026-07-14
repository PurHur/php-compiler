--TEST--
DOMDocument::validate() — in-document DTD validation (#18833, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);

function dom_validate(string $label, string $xml): bool
{
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    $result = $doc->validate();
    restore_error_handler();

    echo $label . ':validate=' . ($result ? 'true' : 'false') . "\n";
    foreach ($warnings as $warning) {
        echo $label . ':warning:' . $warning . "\n";
    }

    return $result;
}

if (!method_exists('DOMDocument', 'validate')) {
    echo "missing-method\n";
    exit(0);
}

$noDtd = dom_validate('no-dtd', '<root/>');
$valid = dom_validate('valid-dtd', '<!DOCTYPE root [<!ELEMENT root (#PCDATA)>]><root>ok</root>');
$invalid = dom_validate('invalid-dtd', '<!DOCTYPE root [<!ELEMENT root EMPTY>]><root>text</root>');

echo ($noDtd ? 'no-dtd-bad' : 'no-dtd-ok') . "\n";
echo ($valid ? 'valid-ok' : 'valid-bad') . "\n";
echo ($invalid ? 'invalid-bad' : 'invalid-ok') . "\n";
--EXPECT--
no-dtd:validate=false
no-dtd:warning:DOMDocument::validate(): no DTD found!
valid-dtd:validate=true
invalid-dtd:validate=false
invalid-dtd:warning:DOMDocument::validate(): Element root was declared EMPTY this one has content
no-dtd-ok
valid-ok
invalid-ok
