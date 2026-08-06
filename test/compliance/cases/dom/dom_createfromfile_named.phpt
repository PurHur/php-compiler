--TEST--
Dom\HTMLDocument/XMLDocument::createFromFile named path/options (#27924)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living API requires PHP_COMPILER_PROFILE=8.4 (#27924)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$hf = tempnam(sys_get_temp_dir(), 'h');
file_put_contents($hf, '<!DOCTYPE html><html><body><p>hi</p></body></html>');
$xf = tempnam(sys_get_temp_dir(), 'x');
file_put_contents($xf, '<?xml version="1.0"?><r/>');

$html = Dom\HTMLDocument::createFromFile(
    path: $hf,
    options: LIBXML_NOERROR
);
echo 'html=', $html->documentElement?->nodeName ?? '(none)', "\n";
$xml = Dom\XMLDocument::createFromFile(path: $xf, options: 0);
echo 'xml=', $xml->documentElement?->nodeName ?? '(none)', "\n";
echo Dom\HTMLDocument::createFromFile($hf, LIBXML_NOERROR)
    ->getElementsByTagName('p')->item(0)?->nodeName ?? '(none)', "\n";

@unlink($hf);
@unlink($xf);
--EXPECT--
html=HTML
xml=r
P
