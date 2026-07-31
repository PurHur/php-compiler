<?php

declare(strict_types=1);

/**
 * Repro: Dom\Attr::$name is QName under PROFILE≥8.4; legacy DOMAttr::$name stays local (#26024).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_dom_attr_name_qname.php
 */

$h = Dom\HTMLDocument::createEmpty();
$root = $h->createElement('root');
$h->append($root);
$attr = $h->createAttributeNS('http://example.com', 'ex:foo');
$attr->value = 'v';
$root->setAttributeNodeNS($attr);
echo 'living name=', $attr->name, ' nodeName=', $attr->nodeName,
    ' localName=', $attr->localName, ' prefix=', $attr->prefix, "\n";

$d = new DOMDocument();
$d->appendChild($d->createElement('r'));
$legacy = $d->createAttributeNS('http://example.com', 'ex:foo');
echo 'legacy name=', $legacy->name, ' nodeName=', $legacy->nodeName,
    ' localName=', $legacy->localName, "\n";
