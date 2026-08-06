<?php

declare(strict_types=1);

// #28227 — legacy DOMElement::$classList must not exist under PROFILE≥8.4
// (php-src ext/dom/php_dom.stub.php — classList on Dom\Element only).
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28227_dom_element_classlist_phantom.php

if (class_exists('DOMTokenList')) {
    fwrite(STDERR, "DOMTokenList must not exist (Zend 8.4 has Dom\\TokenList only)\n");
    exit(1);
}
if (!class_exists('Dom\\TokenList')) {
    fwrite(STDERR, "Dom\\TokenList missing (requires PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}
if ((new ReflectionClass(DOMElement::class))->hasProperty('classList')) {
    fwrite(STDERR, "DOMElement must not advertise classList\n");
    exit(1);
}

$dom = new DOMDocument();
$el = $dom->createElement('div');
$el->setAttribute('class', 'a b');

$warned = false;
set_error_handler(static function (int $n, string $m) use (&$warned): bool {
    if (str_contains($m, 'Undefined property: DOMElement::$classList')) {
        $warned = true;
    }

    return true;
});
$result = $el->classList;
if (!$warned) {
    fwrite(STDERR, "expected Undefined property warning for DOMElement::\$classList\n");
    exit(1);
}
if (null !== $result) {
    fwrite(STDERR, 'expected null, got ' . var_export($result, true) . "\n");
    exit(1);
}

$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div class="a b" id="d"></div></body></html>'
);
$living = $html->getElementById('d');
if (!$living->classList instanceof Dom\TokenList) {
    fwrite(STDERR, 'Dom\\HTMLElement::$classList must be Dom\\TokenList, got '
        . get_class($living->classList) . "\n");
    exit(1);
}
if ($living->classList->value !== 'a b') {
    fwrite(STDERR, 'living classList value expected "a b", got '
        . var_export($living->classList->value, true) . "\n");
    exit(1);
}

echo "ok\n";
