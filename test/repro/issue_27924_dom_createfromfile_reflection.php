<?php
declare(strict_types=1);

/**
 * #27924 — Dom\HTMLDocument/XMLDocument::createFromFile Reflection + named path:
 * (mirror #26080 createFromString).
 */

$f = tempnam(sys_get_temp_dir(), 'h');
file_put_contents($f, '<html><body>x</body></html>');
$xf = tempnam(sys_get_temp_dir(), 'x');
file_put_contents($xf, '<?xml version="1.0"?><root/>');

foreach ([Dom\HTMLDocument::class, Dom\XMLDocument::class] as $c) {
    foreach (['createFromString', 'createFromFile'] as $m) {
        $rf = new ReflectionMethod($c, $m);
        echo $c, '::', $m, ' arity=', $rf->getNumberOfParameters(),
            ' req=', $rf->getNumberOfRequiredParameters();
        $rt = $rf->getReturnType();
        echo ' ret=', $rt ? (string) $rt : '(none)', "\n";
        $parts = [];
        foreach ($rf->getParameters() as $p) {
            $parts[] = $p->getName()
                .':'
                .($p->hasType() ? (string) $p->getType() : '-')
                .':'.($p->isOptional() ? 'OPT' : 'REQ');
        }
        echo '  params=', implode(',', $parts), "\n";
    }
}

try {
    $doc = Dom\HTMLDocument::createFromFile(path: $f, options: LIBXML_NOERROR);
    echo 'html_named=', $doc instanceof Dom\HTMLDocument ? 'ok' : get_class($doc), "\n";
} catch (Throwable $e) {
    echo 'html_named=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $doc = Dom\XMLDocument::createFromFile(path: $xf);
    echo 'xml_named=', $doc instanceof Dom\XMLDocument ? 'ok' : get_class($doc), "\n";
} catch (Throwable $e) {
    echo 'xml_named=', get_class($e), ':', $e->getMessage(), "\n";
}

// Regression guard for #26080
$html = Dom\HTMLDocument::createFromString(source: '<p>x</p>', options: LIBXML_NOERROR);
echo 'string_named=', $html->documentElement?->nodeName ?? '(none)', "\n";

@unlink($f);
@unlink($xf);
