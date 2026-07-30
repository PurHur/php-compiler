<?php

/**
 * Repro #25148 — SimpleXMLElement::children($ns)->attributes() first-child attrs
 * (and null when the children view is empty).
 *
 * Run: php bin/vm.php test/repro/maintainer_gap_simplexml_children_attributes.php
 */

$x = simplexml_load_string('<r xmlns:a="urn:a"><a:c b="1" c="2">t</a:c><d id="x"/></r>');
$attrs = $x->children('urn:a')->attributes();
if (null === $attrs) {
    echo "fail: attrs null on non-empty children view\n";
    exit(1);
}
if (2 !== count($attrs)) {
    echo 'fail: count=', count($attrs), "\n";
    exit(1);
}
$got = [];
foreach ($attrs as $k => $v) {
    $got[(string) $k] = (string) $v;
}
if (['b' => '1', 'c' => '2'] !== $got) {
    echo 'fail: got=', var_export($got, true), "\n";
    exit(1);
}

$empty = simplexml_load_string('<r xmlns:a="urn:a"><y/></r>');
$none = $empty->children('urn:a')->attributes();
if (null !== $none) {
    echo 'fail: empty children attrs expected null, got ', get_debug_type($none), "\n";
    exit(1);
}

$missing = simplexml_load_string('<r><a/></r>')->missing->attributes();
if (null !== $missing) {
    echo 'fail: missing named-child attrs expected null, got ', get_debug_type($missing), "\n";
    exit(1);
}

// Control: attributes($ns) on a real element still works (re-#19554).
$y = simplexml_load_string('<r xmlns:p="urn:p" p:a="1" b="2"/>');
if (1 !== count($y->attributes('urn:p')) || 1 !== count($y->attributes())) {
    echo "fail: element attributes() control\n";
    exit(1);
}

echo "ok\n";
