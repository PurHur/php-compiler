<?php

declare(strict_types=1);

// DOMTokenList is PHP 8.4+ (ext/dom/token_list.c). Run with:
// PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_dom_element_class_list.php
if (!class_exists('DOMTokenList')) {
    fwrite(STDERR, "DOMTokenList class missing (requires PHP_COMPILER_PROFILE=8.4 forward profile)\n");
    exit(1);
}

$dom = new DOMDocument();
$el = $dom->createElement('div');
$dom->appendChild($el);

$el->classList->add('a', 'b');
if ($el->getAttribute('class') !== 'a b') {
    fwrite(STDERR, 'class after add expected "a b", got ' . var_export($el->getAttribute('class'), true) . "\n");
    exit(1);
}
if (!$el->classList->contains('a')) {
    fwrite(STDERR, "contains('a') should be true\n");
    exit(1);
}
if ($el->classList->length !== 2) {
    fwrite(STDERR, 'length expected 2, got ' . $el->classList->length . "\n");
    exit(1);
}
if ($el->classList->item(0) !== 'a' || $el->classList->item(1) !== 'b') {
    fwrite(STDERR, "item() mismatch\n");
    exit(1);
}
if (!$el->classList->toggle('c')) {
    fwrite(STDERR, "toggle('c') should return true\n");
    exit(1);
}
if ($el->getAttribute('class') !== 'a b c') {
    fwrite(STDERR, 'class after toggle expected "a b c", got ' . var_export($el->getAttribute('class'), true) . "\n");
    exit(1);
}
$el->classList->remove('b');
if ($el->getAttribute('class') !== 'a c') {
    fwrite(STDERR, 'class after remove expected "a c", got ' . var_export($el->getAttribute('class'), true) . "\n");
    exit(1);
}

echo "ok\n";
