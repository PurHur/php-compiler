--TEST--
class cannot extend final XMLParser (php-src ext/xml/xml.stub.php; #28386)
--FILE--
<?php
class BadXmlParser extends XMLParser {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadXmlParser cannot extend final class XMLParser
