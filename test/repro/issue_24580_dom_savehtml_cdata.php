<?php
/**
 * DOMDocument::saveHTML() must serialize CDATA as text (libxml htmlNodeDump), not throw.
 * saveXML() keeps <![CDATA[…]]> (#24580, php-src ext/dom/document.c).
 */
$d = new DOMDocument();
$d->loadXML('<root><![CDATA[hello & <world>]]></root>');
echo 'html=', $d->saveHTML();
$cd = $d->documentElement->firstChild;
echo 'node=', $d->saveHTML($cd), "\n";
echo 'xml=', $d->saveXML();
echo 'type=', $cd->nodeType, "\n";
