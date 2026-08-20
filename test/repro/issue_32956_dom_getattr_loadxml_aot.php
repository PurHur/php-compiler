<?php

declare(strict_types=1);

// AOT: loadXML root getAttribute must return the attribute value, not HTML-id stub "target" (#32956).
$d = new DOMDocument();
$d->loadXML('<r x="1" y="2"><a/></r>');
$el = $d->documentElement;
echo $el->getAttribute('x'), '|', $el->getAttribute('y'), '|', $el->hasAttribute('x') ? 'Y' : 'N';
$c = $el->cloneNode(true);
echo '|', $c->getAttribute('x');
