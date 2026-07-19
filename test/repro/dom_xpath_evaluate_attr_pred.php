<?php
/** Repro #21148 — evaluate() attr predicates must match query()/Zend. */
$doc = new DOMDocument();
$doc->loadXML('<r><a id="1">x</a><a id="2">y</a><a id="3">z</a></r>');
$xp = new DOMXPath($doc);

echo 'query: ', $xp->query('//a[@id="2"]')->length, "\n";

try {
    $v = $xp->evaluate('//a[@id="2"]');
    echo 'evaluate nodeset: ', $v instanceof DOMNodeList ? $v->length : var_export($v, true), "\n";
} catch (Throwable $e) {
    echo 'evaluate nodeset: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    echo 'string(/r/a): ', var_export($xp->evaluate('string(/r/a[@id="2"])'), true), "\n";
} catch (Throwable $e) {
    echo 'string(/r/a): ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    echo 'string(//@id): ', var_export($xp->evaluate('string(//a[@id="2"]/@id)'), true), "\n";
} catch (Throwable $e) {
    echo 'string(//@id): ', get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'count: ', var_export($xp->evaluate('count(//a[@id="2"])'), true), "\n";
echo 'number: ', var_export($xp->evaluate('number(/r/a[@id="2"]/@id)'), true), "\n";
