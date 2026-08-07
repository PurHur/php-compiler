--TEST--
XMLParser ReflectionClass::isFinal() (php-src ext/xml/xml.stub.php; #28386)
--FILE--
<?php
xml_parser_create();
echo (new ReflectionClass(XMLParser::class))->isFinal() ? "xmlparser_final_yes\n" : "xmlparser_final_no\n";
?>
--EXPECT--
xmlparser_final_yes
