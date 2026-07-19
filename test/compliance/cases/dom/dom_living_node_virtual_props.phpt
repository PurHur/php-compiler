--TEST--
Dom\Node virtual props isset + Element nodeValue null + baseURI about:blank (#21053, #21054, #21055, #21056)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div id="x">a<!--c-->b</div></body></html>',
    LIBXML_NOERROR
);
$el = $doc->getElementById('x');
echo 'isset_textContent=', isset($el->textContent) ? 'yes' : 'no', "\n";
echo 'isset_ownerDocument=', isset($el->ownerDocument) ? 'yes' : 'no', "\n";
echo 'isset_isConnected=', isset($el->isConnected) ? 'yes' : 'no', "\n";
echo 'isset_baseURI=', isset($el->baseURI) ? 'yes' : 'no', "\n";
echo 'baseURI=', var_export($el->baseURI, true), "\n";
echo 'documentURI=', var_export($doc->documentURI, true), "\n";
echo 'el_nodeValue=', var_export($el->nodeValue, true), "\n";
echo 'isset_el_nodeValue=', isset($el->nodeValue) ? 'yes' : 'no', "\n";
echo 'el_textContent=', var_export($el->textContent, true), "\n";
try {
    $el->nodeValue = 'x';
    echo "el_nodeValue_wrote\n";
} catch (Error $e) {
    echo 'el_nodeValue_ro=yes', "\n";
}

$text = $el->firstChild;
$comment = $text->nextSibling;
echo 'isset_text_nodeValue=', isset($text->nodeValue) ? 'yes' : 'no', "\n";
echo 'isset_text_data=', isset($text->data) ? 'yes' : 'no', "\n";
echo 'isset_text_length=', isset($text->length) ? 'yes' : 'no', "\n";
echo 'isset_text_textContent=', isset($text->textContent) ? 'yes' : 'no', "\n";
echo 'isset_comment_data=', isset($comment->data) ? 'yes' : 'no', "\n";
echo 'isset_comment_nodeValue=', isset($comment->nodeValue) ? 'yes' : 'no', "\n";

$legacy = new DOMDocument();
$legacy->loadXML('<r>ab</r>');
echo 'legacy_nodeValue=', var_export($legacy->documentElement->nodeValue, true), "\n";
?>
--EXPECT--
isset_textContent=yes
isset_ownerDocument=yes
isset_isConnected=yes
isset_baseURI=yes
baseURI='about:blank'
documentURI='about:blank'
el_nodeValue=NULL
isset_el_nodeValue=no
el_textContent='ab'
el_nodeValue_ro=yes
isset_text_nodeValue=yes
isset_text_data=yes
isset_text_length=yes
isset_text_textContent=yes
isset_comment_data=yes
isset_comment_nodeValue=yes
legacy_nodeValue='ab'
