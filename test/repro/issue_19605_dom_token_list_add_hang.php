<?php

declare(strict_types=1);

// PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_19605_dom_token_list_add_hang.php
if (!class_exists('DOMTokenList')) {
    fwrite(STDERR, "DOMTokenList class missing (requires PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}

$doc = new DOMDocument();
$doc->loadXML('<root><e class="a"/></root>');
$e = $doc->documentElement->firstChild;
$e->classList->add('b');
if ($e->getAttribute('class') !== 'a b') {
    fwrite(STDERR, 'expected "a b", got ' . var_export($e->getAttribute('class'), true) . "\n");
    exit(1);
}
$e->classList->remove('a');
if ($e->getAttribute('class') !== 'b') {
    fwrite(STDERR, 'expected "b" after remove, got ' . var_export($e->getAttribute('class'), true) . "\n");
    exit(1);
}
if (!$e->classList->toggle('c')) {
    fwrite(STDERR, "toggle('c') should return true\n");
    exit(1);
}
if ($e->getAttribute('class') !== 'b c') {
    fwrite(STDERR, 'expected "b c" after toggle, got ' . var_export($e->getAttribute('class'), true) . "\n");
    exit(1);
}
if (!$e->classList->replace('b', 'd')) {
    fwrite(STDERR, "replace('b','d') should return true\n");
    exit(1);
}
if ($e->getAttribute('class') !== 'd c') {
    fwrite(STDERR, 'expected "d c" after replace, got ' . var_export($e->getAttribute('class'), true) . "\n");
    exit(1);
}

echo "ok\n";
