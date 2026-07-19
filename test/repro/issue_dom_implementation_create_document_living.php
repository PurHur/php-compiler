<?php
/**
 * Dom\Implementation::createDocument / createDocumentType living return types (#20910).
 */
$impl = Dom\HTMLDocument::createEmpty()->implementation;
echo 'impl=', get_class($impl), "\n";

$doc = $impl->createDocument(null, 'root');
echo 'createDocument=', get_class($doc), "\n";
echo 'root=', get_class($doc->documentElement), "\n";
echo 'xmlVersion=', var_export($doc->xmlVersion, true), "\n";

$nsDoc = $impl->createDocument('http://example.com/ns', 'ex:root');
echo 'nsRoot=', get_class($nsDoc->documentElement), "\n";
echo 'nsURI=', var_export($nsDoc->documentElement->namespaceURI, true), "\n";

$dt = $impl->createDocumentType('html', '', '');
echo 'createDocumentType=', get_class($dt), "\n";
echo 'dt.name=', $dt->name, "\n";

$withDt = $impl->createDocument(null, 'html', $dt);
echo 'withDt=', get_class($withDt), "\n";
echo 'withDt.doctype=', get_class($withDt->doctype), "\n";

$html = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>');
echo 'html.doctype=', get_class($html->doctype), "\n";

$legacy = (new DOMImplementation())->createDocument(null, 'legacy');
echo 'legacy=', get_class($legacy), "\n";
