<?php
/**
 * #26060 — DOMNode / Dom\Node DOCUMENT_POSITION_* reachable under PROFILE≥8.4.
 *
 * php-src: ext/dom/php_dom.stub.php (PHP-8.4+)
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26060_document_position_constants.php
 */
$r = new ReflectionClass(DOMNode::class);
$consts = $r->getConstants();
ksort($consts);
echo 'DOMNode reflect=', count($consts), "\n";
foreach ($consts as $name => $value) {
    if (!str_starts_with($name, 'DOCUMENT_POSITION_')) {
        fwrite(STDERR, "unexpected DOMNode const $name\n");
        exit(1);
    }
    if (constant('DOMNode::'.$name) !== $value) {
        fwrite(STDERR, "DOMNode::$name runtime/Reflection mismatch\n");
        exit(1);
    }
    echo "DOMNode::$name=$value\n";
}

$r2 = new ReflectionClass(Dom\Node::class);
$consts2 = $r2->getConstants();
ksort($consts2);
echo 'Dom\\Node reflect=', count($consts2), "\n";
foreach ($consts2 as $name => $value) {
    if (!str_starts_with($name, 'DOCUMENT_POSITION_')) {
        fwrite(STDERR, "unexpected Dom\\Node const $name\n");
        exit(1);
    }
    if (constant('Dom\\Node::'.$name) !== $value) {
        fwrite(STDERR, "Dom\\Node::$name runtime/Reflection mismatch\n");
        exit(1);
    }
    echo "Dom\\Node::$name=$value\n";
}

try {
    echo Dom\Node::document_position_contained_by;
    fwrite(STDERR, "wrong-case Dom\\Node fetch should fail\n");
    exit(1);
} catch (Error $e) {
    echo "wrong_case_ok\n";
}

echo "ok\n";
