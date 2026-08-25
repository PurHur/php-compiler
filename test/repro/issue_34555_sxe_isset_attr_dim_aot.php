<?php
/**
 * AOT: isset/empty on SimpleXMLElement attribute dims (#34555).
 * php-src: ext/simplexml/sxe.c — sxe_object_has_dimension
 */
$xml = simplexml_load_string('<r a="1" b=""><c/></r>');
foreach (['a', 'b', 'missing', 0] as $k) {
    echo $k, ':', isset($xml[$k]) ? 'I' : 'i', empty($xml[$k]) ? 'E' : 'e', '|';
}
echo "\n";
