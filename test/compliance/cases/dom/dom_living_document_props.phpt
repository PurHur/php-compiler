--TEST--
Dom\Document URL/documentURI/characterSet/doctype/implementation (#20898)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20898)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>');
echo 'URL=', $d->URL, "\n";
echo 'documentURI=', $d->documentURI, "\n";
echo 'characterSet=', $d->characterSet, "\n";
echo 'charset=', $d->charset, "\n";
echo 'inputEncoding=', $d->inputEncoding, "\n";
$dt = $d->doctype;
echo 'doctype=', ($dt !== null ? $dt->nodeName : 'null'), "\n";
echo 'implementation=', get_class($d->implementation), "\n";
echo 'createHTMLDocument=', method_exists($d->implementation, 'createHTMLDocument') ? 'yes' : 'no', "\n";
$h = $d->implementation->createHTMLDocument('T');
echo 'fromImpl=', get_class($h), ' title=', $h->title, "\n";

$e = Dom\HTMLDocument::createEmpty('ISO-8859-1');
echo 'emptyCharset=', $e->characterSet, "\n";

$x = Dom\XMLDocument::createEmpty();
echo 'xmlVersion=', $x->xmlVersion, "\n";
echo 'xmlStandalone=', $x->xmlStandalone ? 'true' : 'false', "\n";
echo 'xmlEncoding=', $x->xmlEncoding, "\n";

$path = sys_get_temp_dir() . '/phpc_dom_doc_props_' . getmypid() . '.html';
file_put_contents($path, '<!DOCTYPE html><html><body>x</body></html>');
$f = Dom\HTMLDocument::createFromFile($path);
echo 'fileURI=', $f->documentURI, "\n";
@unlink($path);

// Legacy DOMDocument still uses cwd documentURI (not about:blank).
$leg = new DOMDocument();
$leg->loadXML('<r/>');
$legUri = $leg->documentURI;
echo 'legacyHasUri=', (is_string($legUri) && $legUri !== '' && $legUri !== 'about:blank') ? 'yes' : 'no', "\n";
?>
--EXPECTF--
URL=about:blank
documentURI=about:blank
characterSet=UTF-8
charset=UTF-8
inputEncoding=UTF-8
doctype=html
implementation=Dom\Implementation
createHTMLDocument=yes
fromImpl=Dom\HTMLDocument title=T
emptyCharset=ISO-8859-1
xmlVersion=1.0
xmlStandalone=false
xmlEncoding=UTF-8
fileURI=%s
legacyHasUri=yes
