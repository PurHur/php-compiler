<?php
/**
 * Repro: DOMXPath::evaluate() XPath 1.0 string/number core functions
 * (concat, substring*, starts-with, contains, normalize-space, translate,
 * string-length, floor, ceiling, round) — php-src ext/dom/xpath.c / libxml.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a>hello</a><b> world</b></r>');
$xpath = new DOMXPath($doc);

$checks = [
    'concat("x","y")' => 'xy',
    'concat("a","b","c")' => 'abc',
    'concat(string(//a), string(//b))' => 'hello world',
    'starts-with("hello","he")' => true,
    'contains("hello","ell")' => true,
    'substring("hello",2,3)' => 'ell',
    'substring-before("a,b",",")' => 'a',
    'substring-after("a,b",",")' => 'b',
    'string-length("hello")' => 5.0,
    'normalize-space("  a  b  ")' => 'a b',
    'translate("abc","ac","AC")' => 'AbC',
    'floor(3.7)' => 3.0,
    'ceiling(3.2)' => 4.0,
    'round(3.5)' => 4.0,
];

$failed = 0;
foreach ($checks as $expr => $expect) {
    try {
        $got = $xpath->evaluate($expr);
    } catch (Throwable $e) {
        echo "FAIL $expr => ERR ".$e->getMessage()."\n";
        ++$failed;
        continue;
    }
    $ok = $got === $expect
        || (is_float($expect) && is_float($got) && abs($got - $expect) < 1e-9);
    if (!$ok) {
        echo 'FAIL '.$expr.' => '.var_export($got, true).' expected '.var_export($expect, true)."\n";
        ++$failed;
    } else {
        echo "OK $expr\n";
    }
}

echo $failed === 0 ? "ALL_OK\n" : "FAILED=$failed\n";
exit($failed === 0 ? 0 : 1);
