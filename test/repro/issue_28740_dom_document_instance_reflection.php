<?php
declare(strict_types=1);

/**
 * #28740 — Dom\HTMLDocument/XMLDocument instance-method Reflection arity + named args
 * (getElementById / saveHtml / saveXml residual after #26080 / #27924).
 */

error_reporting(E_ALL);

$html = Dom\HTMLDocument::createFromString('<p id="a">x</p>', LIBXML_NOERROR);
$rc = new ReflectionClass(Dom\HTMLDocument::class);
foreach (['getElementById', 'saveHtml', 'saveXml'] as $m) {
    $rm = $rc->getMethod($m);
    echo $m, ' arity=', $rm->getNumberOfParameters(),
        ' req=', $rm->getNumberOfRequiredParameters();
    $rt = $rm->getReturnType();
    echo ' ret=', $rt ? (string) $rt : '(none)', "\n";
    $parts = [];
    foreach ($rm->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ');
    }
    echo '  params=', implode(',', $parts), "\n";
}

$el = $html->getElementById(elementId: 'a');
echo 'named_id=', $el?->tagName ?? '(null)', "\n";
echo 'named_html_len=', strlen($html->saveHtml(node: $el)), "\n";
$xmlOut = $html->saveXml(node: $el, options: 0);
echo 'named_xml=', is_string($xmlOut) ? 'str:'.strlen($xmlOut) : gettype($xmlOut), "\n";
echo 'pos_id=', $html->getElementById('a')?->tagName ?? '(null)', "\n";
echo 'pos_html_len=', strlen($html->saveHtml()), "\n";

$x = Dom\XMLDocument::createFromString('<?xml version="1.0"?><root xml:id="a"/>');
$rcx = new ReflectionClass(Dom\XMLDocument::class);
foreach (['getElementById', 'saveXml'] as $m) {
    $rm = $rcx->getMethod($m);
    echo 'XML ', $m, ' arity=', $rm->getNumberOfParameters();
    $rt = $rm->getReturnType();
    echo ' ret=', $rt ? (string) $rt : '(none)', "\n";
}
echo 'xml_named_id=', $x->getElementById(elementId: 'a')?->nodeName ?? '(null)', "\n";
$xmlOnly = $x->saveXml(node: $x->documentElement, options: 0);
echo 'xml_named_save=', is_string($xmlOnly) ? 'str:'.strlen($xmlOnly) : gettype($xmlOnly), "\n";
