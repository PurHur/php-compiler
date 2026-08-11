<?php
/**
 * DOMXPath::query() with syntactically invalid XPath must return false (+ E_WARNING),
 * not an empty DOMNodeList. Zend/php-src: ext/dom/xpath.c xmlXPathEval failure path.
 *
 * Regression of #22721 — the hand-rolled evaluator silently swallowed malformed
 * expressions (unmatched brackets, triple slashes) as empty result sets.
 */

$doc = new DOMDocument();
$doc->loadXML('<root><item id="a">1</item></root>');
$xpath = new DOMXPath($doc);

$cases = [
    '///invalid['   => 'triple slash + unmatched bracket',
    '//item['       => 'unmatched bracket',
    '//item('       => 'unmatched paren',
];

$pass = true;
foreach ($cases as $expr => $label) {
    $result = @$xpath->query($expr);
    if (false !== $result) {
        echo "FAIL ($label): expected false, got " . gettype($result) . "\n";
        $pass = false;
    } else {
        echo "OK ($label): false\n";
    }
}

// Valid query must still work
$valid = $xpath->query('//item[@id="a"]');
if (!$valid || $valid->length !== 1) {
    echo "FAIL: valid query broken\n";
    $pass = false;
} else {
    echo "OK: valid query returns 1 node\n";
}

echo $pass ? "PASS\n" : "FAIL\n";
