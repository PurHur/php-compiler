--TEST--
AOT Dom\HTMLDocument::saveHtml after createFromString (#31324)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString('<p id="p">x</p>', LIBXML_NOERROR);
echo 'doc=', $html->saveHtml(), "\n";
$i = $html->createElement('i');
echo 'node=', $html->saveHtml($i), "\n";
$html2 = Dom\HTMLDocument::createFromString('<p>y</p>');
echo 'doc0=', $html2->saveHtml(), "\n";
--EXPECT--
doc=<html><head></head><body><p id="p">x</p></body></html>
node=<i></i>
doc0=<html><head></head><body><p>y</p></body></html>
