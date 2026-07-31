--TEST--
stdlib phpversion('xml') matches reported PHP version (#25819, ext/xml/xml.c)
--FILE--
<?php
declare(strict_types=1);

$core = phpversion();
echo phpversion('xml') === $core ? "xml_ok\n" : "xml_bad\n";
echo phpversion('libxml') === $core ? "libxml_ok\n" : "libxml_bad\n";
echo phpversion('simplexml') === $core ? "simplexml_ok\n" : "simplexml_bad\n";
echo phpversion('xmlreader') === $core ? "xmlreader_ok\n" : "xmlreader_bad\n";
echo phpversion('xmlwriter') === $core ? "xmlwriter_ok\n" : "xmlwriter_bad\n";
echo phpversion('dom') === '20031129' ? "dom_ok\n" : "dom_bad\n";
--EXPECT--
xml_ok
libxml_ok
simplexml_ok
xmlreader_ok
xmlwriter_ok
dom_ok
