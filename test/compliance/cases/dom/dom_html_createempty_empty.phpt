--TEST--
Dom\HTMLDocument::createEmpty() starts empty — first append becomes documentElement (#26035)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#26035)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$dom = Dom\HTMLDocument::createEmpty();
echo 'before=', $dom->documentElement ? $dom->documentElement->nodeName : 'NULL', "\n";
echo 'body=', $dom->body !== null ? 'set' : 'NULL', "\n";
echo 'saveHtml=', var_export($dom->saveHtml(), true), "\n";
$dom->appendChild($dom->createElement('template'));
echo 'after=', $dom->documentElement ? $dom->documentElement->nodeName : 'NULL', "\n";
echo 'childElementCount=', $dom->childElementCount, "\n";
$xml = str_replace("\n", ' ', trim($dom->saveXml()));
echo 'saveXml_template_only=', (str_contains($xml, '<template') && !str_contains($xml, '<html')) ? 'yes' : 'no', "\n";

$impl = Dom\HTMLDocument::createEmpty()->implementation->createHTMLDocument('T');
echo 'impl_root=', $impl->documentElement ? $impl->documentElement->nodeName : 'NULL', "\n";
echo 'impl_title=', $impl->title, "\n";
echo 'impl_body=', $impl->body !== null ? 'set' : 'NULL', "\n";
?>
--EXPECT--
before=NULL
body=NULL
saveHtml=''
after=TEMPLATE
childElementCount=1
saveXml_template_only=yes
impl_root=HTML
impl_title=T
impl_body=set
