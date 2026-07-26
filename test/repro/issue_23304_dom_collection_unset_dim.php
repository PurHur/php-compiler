<?php

/**
 * Repro #23304 — unset($DOMNodeList[$i]) / unset($DOMNamedNodeMap[$k]) must Error
 * like Zend (re-#20311; no unset_dimension on collections).
 */

$doc = new DOMDocument();
$doc->loadXML('<root a="1" b="2"><child/></root>');
$list = $doc->getElementsByTagName('*');
$map = $doc->documentElement->attributes;

foreach (
    [
        'nl' => static function () use ($list): void {
            unset($list[0]);
        },
        'nnm' => static function () use ($map): void {
            unset($map['a']);
        },
        'std' => static function (): void {
            $o = new stdClass();
            unset($o[0]);
        },
    ] as $label => $fn
) {
    try {
        $fn();
        echo $label, "=ok\n";
    } catch (Error $e) {
        echo $label, '=', $e->getMessage(), "\n";
    }
}

// Keepers from #20311
echo 'isset=', isset($list[0]) ? '1' : '0', "\n";
echo 'read=', $list[0]->nodeName, "\n";
try {
    $list[0] = 1;
    echo "write=ok\n";
} catch (Error $e) {
    echo 'write=', $e->getMessage(), "\n";
}
