<?php
declare(strict_types=1);

// #26080 — Dom\HTMLDocument/XMLDocument::createFromString Reflection + named args (PROFILE=8.4)
foreach ([Dom\HTMLDocument::class, Dom\XMLDocument::class] as $c) {
    $m = new ReflectionMethod($c, 'createFromString');
    $n = [];
    foreach ($m->getParameters() as $p) {
        $n[] = $p->getName().':'.($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ');
    }
    echo $c, '::createFromString arity=', $m->getNumberOfParameters(),
        ' req=', $m->getNumberOfRequiredParameters(),
        ' [', implode(',', $n), ']', PHP_EOL;
}
$html = Dom\HTMLDocument::createFromString(
    source: '<!DOCTYPE html><html><body><p>hi</p></body></html>',
    options: LIBXML_NOERROR
);
echo 'named_html=', $html->documentElement?->nodeName ?? '(none)', PHP_EOL;
$xml = Dom\XMLDocument::createFromString(source: '<r/>', options: 0);
echo 'named_xml=', $xml->documentElement?->nodeName ?? '(none)', PHP_EOL;
