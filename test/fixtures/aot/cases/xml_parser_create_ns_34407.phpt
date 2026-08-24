--TEST--
AOT xml_parser_create_ns + xml_parse_into_struct expands namespaced tags (#34407)
--FILE--
<?php
$xml = '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>';
$p = xml_parser_create_ns();
$vals = [];
xml_parse_into_struct($p, $xml, $vals);
$tags = [];
foreach ($vals as $row) {
    $tags[] = $row['tag'];
}
echo implode(',', $tags), "\n";
$p2 = xml_parser_create_ns(null, ' ');
xml_parser_set_option($p2, XML_OPTION_CASE_FOLDING, 0);
$vals2 = [];
xml_parse_into_struct($p2, $xml, $vals2);
$tags2 = [];
foreach ($vals2 as $row) {
    $tags2[] = $row['tag'];
}
echo implode(',', $tags2), "\n";
--EXPECT--
URN:X:R,URN:X:A,URN:X:R
urn:x r,urn:x a,urn:x r
