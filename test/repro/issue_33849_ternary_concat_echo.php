<?php

declare(strict_types=1);

// #33849 — AOT: concat inside ternary true arm must feed the echo phi (not an ephemeral),
// and in-place CONCAT($prop,$prop,lit) must not write-fetch / empty DOMElement::$nodeName.
// php-src: Zend/zend_operators.c concat_function; Zend/zend_compile.c ZEND_QM_ASSIGN / JMPNZ;
// ext/dom — readonly nodeName (php-src ext/dom/php_dom.h / node.c).

$a = 'b';
echo $a ? ($a.'x') : 'null', "\n";

echo 'b' ? ('b'.'x') : 'null', "\n";

$d = new DOMDocument();
$d->loadXML('<r><b id="y">2</b></r>');
$e = $d->documentElement->firstChild;
echo $e ? $e->nodeName : 'null', "\n";
echo $e ? ($e->nodeName.'=') : 'null', "\n";
echo 'after=', $e->nodeName, "\n";
echo $e ? ($e->nodeName.'='.$e->textContent) : 'null', "\n";
echo 'after2=', $e->nodeName, "\n";
