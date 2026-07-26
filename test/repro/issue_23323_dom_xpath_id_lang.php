<?php

/**
 * Repro #23323 — DOMXPath id()/lang() XPath 1.0 core (ext/dom/xpath.c).
 */

libxml_use_internal_errors(true);

$doc = new DOMDocument();
$doc->loadXML('<!DOCTYPE r [<!ATTLIST e id ID #IMPLIED>]><r><e id="x"/><e id="y"/><e id="z"/></r>');
$xp = new DOMXPath($doc);

$r = $xp->evaluate('id("x")');
echo 'dtd_type=', $r instanceof DOMNodeList ? 'DOMNodeList' : gettype($r), "\n";
echo 'dtd_len=', $r instanceof DOMNodeList ? $r->length : -1, "\n";
echo 'dtd_id=', ($r instanceof DOMNodeList && $r->length) ? $r->item(0)->getAttribute('id') : '-', "\n";

$q = $xp->query('id("y")');
echo 'query_len=', $q instanceof DOMNodeList ? $q->length : -1, "\n";

$multi = $xp->evaluate('id("x y")');
$ids = [];
if ($multi instanceof DOMNodeList) {
    for ($i = 0; $i < $multi->length; $i++) {
        $ids[] = $multi->item($i)->getAttribute('id');
    }
}
echo 'multi=', implode(',', $ids), "\n";

$ns = $xp->evaluate('id(//@id)');
$nsIds = [];
if ($ns instanceof DOMNodeList) {
    for ($i = 0; $i < $ns->length; $i++) {
        $nsIds[] = $ns->item($i)->getAttribute('id');
    }
}
echo 'nodeset_arg=', implode(',', $nsIds), "\n";

echo 'count=', var_export($xp->evaluate('count(id("x y"))'), true), "\n";

$html = new DOMDocument();
$html->loadHTML('<!DOCTYPE html><html><body><div id="z">Z</div></body></html>', LIBXML_NOERROR);
$hx = new DOMXPath($html);
$hr = $hx->evaluate('id("z")');
echo 'html_len=', $hr instanceof DOMNodeList ? $hr->length : -1, "\n";
echo 'html_text=', ($hr instanceof DOMNodeList && $hr->length) ? $hr->item(0)->textContent : '-', "\n";

$langDoc = new DOMDocument();
$langDoc->loadXML('<r xml:lang="en-US"><a><b/></a></r>');
$lx = new DOMXPath($langDoc);
$b = $langDoc->getElementsByTagName('b')->item(0);
echo 'lang_en=', var_export($lx->evaluate('lang("en")', $b), true), "\n";
echo 'lang_EN=', var_export($lx->evaluate('lang("EN")', $b), true), "\n";
echo 'lang_en_us=', var_export($lx->evaluate('lang("en-us")', $b), true), "\n";
echo 'lang_fr=', var_export($lx->evaluate('lang("fr")', $b), true), "\n";
echo 'lang_eng=', var_export($lx->evaluate('lang("eng")', $b), true), "\n";
