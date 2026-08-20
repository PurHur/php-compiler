<?php
declare(strict_types=1);

/**
 * DOM ChildNode::after()/before() AOT — parent is DOMDocument (#32611; ext/dom/php_dom.c).
 */
$doc = new DOMDocument();
$p = $doc->createElement('p');
$doc->appendChild($p);
$span = $doc->createElement('span');
$p->after($span);
echo "after=ok\n";

$doc2 = new DOMDocument();
$a = $doc2->createElement('a');
$doc2->appendChild($a);
$b = $doc2->createElement('b');
$a->before($b);
echo "before=ok\n";
