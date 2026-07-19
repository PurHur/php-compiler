<?php
/**
 * Dom\Document virtual props — URL/documentURI/characterSet/doctype/implementation (#20898).
 */
$d = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>');
echo 'URL=', var_export($d->URL, true), "\n";
echo 'documentURI=', var_export($d->documentURI, true), "\n";
echo 'characterSet=', var_export($d->characterSet, true), "\n";
echo 'charset=', var_export($d->charset, true), "\n";
echo 'inputEncoding=', var_export($d->inputEncoding, true), "\n";
$dt = $d->doctype;
echo 'doctype=', ($dt !== null ? $dt->nodeName : 'null'), "\n";
echo 'implementation=', get_class($d->implementation), "\n";
echo 'createHTMLDocument=', method_exists($d->implementation, 'createHTMLDocument') ? 'yes' : 'no', "\n";

$e = Dom\HTMLDocument::createEmpty('ISO-8859-1');
echo 'emptyCharset=', var_export($e->characterSet, true), "\n";

$x = Dom\XMLDocument::createEmpty();
echo 'xmlVersion=', var_export($x->xmlVersion, true), "\n";
echo 'xmlStandalone=', var_export($x->xmlStandalone, true), "\n";
echo 'xmlEncoding=', var_export($x->xmlEncoding, true), "\n";
echo 'xmlImpl=', get_class($x->implementation), "\n";

$path = sys_get_temp_dir() . '/phpc_issue_20898_' . getmypid() . '.html';
file_put_contents($path, '<!DOCTYPE html><html><body>x</body></html>');
$f = Dom\HTMLDocument::createFromFile($path);
@unlink($path);
echo 'fileURI=', var_export($f->documentURI, true), "\n";
