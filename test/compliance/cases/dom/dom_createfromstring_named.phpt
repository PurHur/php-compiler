--TEST--
Dom\HTMLDocument/XMLDocument::createFromString named source/options (#26080)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living API requires PHP_COMPILER_PROFILE=8.4 (#26080)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$html = Dom\HTMLDocument::createFromString(
    source: '<!DOCTYPE html><html><body><p>hi</p></body></html>',
    options: LIBXML_NOERROR
);
echo 'html=', $html->documentElement?->nodeName ?? '(none)', "\n";
$xml = Dom\XMLDocument::createFromString(source: '<r/>', options: 0);
echo 'xml=', $xml->documentElement?->nodeName ?? '(none)', "\n";
echo Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><p>x</p></body></html>',
    LIBXML_NOERROR
)->getElementsByTagName('p')->item(0)?->nodeName ?? '(none)', "\n";
--EXPECT--
html=HTML
xml=r
P
