<?php

declare(strict_types=1);

/**
 * Repro #21271 — Dom\XPath string()/number() must use XPath string-value (textContent),
 * not Dom\Element::$nodeValue (always null on living nodes).
 */
if (!class_exists('Dom\\XMLDocument')) {
    fwrite(STDERR, "fail: Dom\\XMLDocument missing (need PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}

$xml = '<r xmlns:n="urn:x"><n:a>1</n:a><b>2</b></r>';
$doc = Dom\XMLDocument::createFromString($xml);
$xp = new Dom\XPath($doc);
$xp->registerNamespace('n', 'urn:x');

$strNa = $xp->evaluate('string(//n:a)');
$strB = $xp->evaluate('string(//b)');
$numNa = $xp->evaluate('number(//n:a)');
$sum = $xp->evaluate('sum(//b)');

$legacy = new DOMDocument();
$legacy->loadXML($xml);
$lxp = new DOMXPath($legacy);
$lxp->registerNamespace('n', 'urn:x');
$legacyStr = $lxp->evaluate('string(//n:a)');

if ('1' !== $strNa || '2' !== $strB) {
    fwrite(STDERR, 'fail: string() living='.var_export($strNa, true).'/'.var_export($strB, true)."\n");
    exit(1);
}
if (1.0 !== (float) $numNa) {
    fwrite(STDERR, 'fail: number() living='.var_export($numNa, true)."\n");
    exit(1);
}
if (2.0 !== (float) $sum) {
    fwrite(STDERR, 'fail: sum() living='.var_export($sum, true)."\n");
    exit(1);
}
if ('1' !== $legacyStr) {
    fwrite(STDERR, 'fail: legacy string()='.var_export($legacyStr, true)."\n");
    exit(1);
}

echo "string_na=1\n";
echo "string_b=2\n";
echo "number_na=1\n";
echo "sum_b=2\n";
echo "legacy_ok\n";
