<?php
declare(strict_types=1);

/**
 * #28741 — Dom\HTMLElement selector Reflection arity + named args
 * (querySelector / querySelectorAll / closest / matches / getElementsByTagName).
 */

error_reporting(E_ALL);

$d = Dom\HTMLDocument::createFromString('<div><span id="s">t</span></div>', LIBXML_NOERROR);
$el = $d->documentElement;
$rc = new ReflectionClass(Dom\HTMLElement::class);
foreach (['querySelector', 'querySelectorAll', 'closest', 'matches', 'getElementsByTagName'] as $m) {
    $rm = $rc->getMethod($m);
    $rt = $rm->getReturnType();
    echo $m, ' arity=', $rm->getNumberOfParameters(),
        ' req=', $rm->getNumberOfRequiredParameters(),
        ' ret=', $rt ? (string) $rt : '(none)', "\n";
    $parts = [];
    foreach ($rm->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ');
    }
    echo '  params=', implode(',', $parts), "\n";
}

echo 'named_qs=', $el->querySelector(selectors: '#s')?->tagName ?? '(null)', "\n";
echo 'named_tag=', $el->getElementsByTagName(qualifiedName: 'span')->length, "\n";
echo 'pos_qs=', $el->querySelector('#s')?->tagName ?? '(null)', "\n";
$span = $el->querySelector('#s');
echo 'matches=', $span->matches(selectors: 'span') ? 'Y' : 'N', "\n";
echo 'closest=', $span->closest(selectors: 'div')?->tagName ?? '(null)', "\n";
$list = $el->querySelectorAll(selectors: 'span');
echo 'named_qsa=', $list->length, "\n";
