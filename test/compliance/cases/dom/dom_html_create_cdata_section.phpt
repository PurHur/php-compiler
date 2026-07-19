--TEST--
Dom\HTMLDocument::createCDATASection throws NOT_SUPPORTED_ERR (#21064)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21064)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString('<html><body></body></html>', LIBXML_NOERROR);
try {
    $cd = $html->createCDATASection('x');
    echo 'html_ok=', get_class($cd), "\n";
} catch (Throwable $e) {
    $cls = ($e instanceof Dom\DOMException) ? 'Dom\\DOMException' : get_class($e);
    echo 'html=', $cls, ' msg=', $e->getMessage(), ' code=', $e->getCode(), "\n";
}

$xml = Dom\XMLDocument::createEmpty();
echo 'xml=', get_class($xml->createCDATASection('x<y>')), "\n";

// Legacy DOMDocument::createCDATASection still succeeds (no follow_spec gate).
$legacy = new DOMDocument();
echo 'legacy=', get_class($legacy->createCDATASection('x')), "\n";
?>
--EXPECT--
html=Dom\DOMException msg=This operation is not supported for HTML documents code=9
xml=Dom\CDATASection
legacy=DOMCdataSection
