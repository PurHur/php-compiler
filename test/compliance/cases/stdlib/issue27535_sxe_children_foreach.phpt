--TEST--
SimpleXMLElement::children() foreach + getName under AOT (#27535)
--FILE--
<?php
$xml = simplexml_load_string("<r><a>1</a><b>2</b></r>");
foreach ($xml->children() as $c) {
    echo $c->getName(), ":", (string) $c, ";";
}
echo "\n";
--EXPECT--
a:1;b:2;
