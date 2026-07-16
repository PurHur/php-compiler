<?php
declare(strict_types=1);

// #19359 — deep cloneNode must keep text/CDATA (php-src ext/dom/node.c)
$dom = new DOMDocument();
$dom->loadXML('<root><a><b>t</b></a></root>');
$c = $dom->getElementsByTagName('a')->item(0)->cloneNode(true);
echo 'clone=', $c->textContent, "\n";
echo 'xml=', $dom->saveXML($c), "\n";

$dom2 = new DOMDocument();
$dom2->loadXML('<root><![CDATA[x<y>]]></root>');
$cd = $dom2->documentElement->firstChild;
echo get_class($cd), "\n";
echo $cd->cloneNode(true)->data, "\n";
