<?php
declare(strict_types=1);

/**
 * #21558 — Dom\HTMLElement tagName/nodeName uppercase (php-src element.c / HTML DOM).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21558_dom_html_tagname_case.php
 */
set_error_handler(static fn () => true);
$html = Dom\HTMLDocument::createFromString('<html><body><div id="d"></div></body></html>');
$d = $html->getElementById('d');
echo 'tag=', var_export($d->tagName, true), PHP_EOL;
echo 'node=', var_export($d->nodeName, true), PHP_EOL;
echo 'local=', var_export($d->localName, true), PHP_EOL;
echo 'body=', var_export($html->body->tagName, true), PHP_EOL;
$created = $html->createElement('SPAN');
echo 'created=', var_export($created->tagName, true), ' local=', var_export($created->localName, true), PHP_EOL;
$svg = $html->createElementNS('http://www.w3.org/2000/svg', 'svg');
echo 'svg=', var_export($svg->tagName, true), PHP_EOL;
$legacy = new DOMDocument();
$legacy->loadHTML('<div id="x"></div>', LIBXML_NOERROR);
$leg = $legacy->getElementById('x');
echo 'legacy=', var_export($leg->tagName, true), PHP_EOL;
$xd = Dom\XMLDocument::createFromString('<root><div/></root>');
$xr = $xd->documentElement->firstElementChild;
echo 'xml=', var_export($xr->tagName, true), PHP_EOL;
