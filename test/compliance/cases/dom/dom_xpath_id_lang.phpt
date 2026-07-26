--TEST--
DOMXPath::evaluate()/query() XPath 1.0 id() and lang() (#23323, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadXML('<!DOCTYPE r [<!ATTLIST e id ID #IMPLIED>]><r><e id="x"/><e id="y"/></r>');
$xp = new DOMXPath($doc);
$r = $xp->evaluate('id("x")');
echo ($r instanceof DOMNodeList && 1 === $r->length && 'x' === $r->item(0)->getAttribute('id')) ? "id_ok\n" : "id_fail\n";
$q = $xp->query('id("y")');
echo ($q instanceof DOMNodeList && 1 === $q->length) ? "query_ok\n" : "query_fail\n";
$multi = $xp->evaluate('id("x y")');
echo ($multi instanceof DOMNodeList && 2 === $multi->length) ? "multi_ok\n" : "multi_fail\n";
echo (2.0 === $xp->evaluate('count(id("x y"))')) ? "count_ok\n" : "count_fail\n";

$html = new DOMDocument();
$html->loadHTML('<!DOCTYPE html><html><body><div id="z">Z</div></body></html>', LIBXML_NOERROR);
$hx = new DOMXPath($html);
$hr = $hx->evaluate('id("z")');
echo ($hr instanceof DOMNodeList && 1 === $hr->length && 'Z' === $hr->item(0)->textContent) ? "html_ok\n" : "html_fail\n";

$langDoc = new DOMDocument();
$langDoc->loadXML('<r xml:lang="en-US"><a><b/></a></r>');
$lx = new DOMXPath($langDoc);
$b = $langDoc->getElementsByTagName('b')->item(0);
echo (true === $lx->evaluate('lang("en")', $b)) ? "lang_en_ok\n" : "lang_en_fail\n";
echo (true === $lx->evaluate('lang("en-us")', $b)) ? "lang_exact_ok\n" : "lang_exact_fail\n";
echo (false === $lx->evaluate('lang("fr")', $b)) ? "lang_fr_ok\n" : "lang_fr_fail\n";
echo (false === $lx->evaluate('lang("eng")', $b)) ? "lang_prefix_ok\n" : "lang_prefix_fail\n";
?>
--EXPECT--
id_ok
query_ok
multi_ok
count_ok
html_ok
lang_en_ok
lang_exact_ok
lang_fr_ok
lang_prefix_ok
