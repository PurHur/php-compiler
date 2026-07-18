--TEST--
ext/xml xml_set_start_namespace_decl_handler() — xmlns decls fire before start element (#20323)
--FILE--
<?php
$p = xml_parser_create_ns();
$start = [];
$end = [];
xml_set_start_namespace_decl_handler($p, function ($parser, $prefix, $uri) use (&$start) {
    $start[] = [false === $prefix ? false : (string) $prefix, (string) $uri];
});
xml_set_end_namespace_decl_handler($p, function ($parser, $prefix) use (&$end) {
    $end[] = false === $prefix ? false : (string) $prefix;
});
$xml = '<?xml version="1.0"?><root xmlns:ex="http://example.com/ns"><ex:item>1</ex:item></root>';
$ok = xml_parse($p, $xml, true);
xml_parser_free($p);
echo json_encode($start), "\n";
echo json_encode($end), "\n";
echo $ok ? "ok\n" : "fail\n";

$p2 = xml_parser_create_ns();
$start2 = [];
xml_parser_set_option($p2, XML_OPTION_CASE_FOLDING, 0);
xml_set_start_namespace_decl_handler($p2, function ($parser, $prefix, $uri) use (&$start2) {
    $start2[] = [false === $prefix ? false : (string) $prefix, (string) $uri];
});
xml_set_element_handler(
    $p2,
    function ($parser, $name, $attrs) { echo 'S:', $name, "\n"; },
    function ($parser, $name) { echo 'E:', $name, "\n"; }
);
xml_parse($p2, '<root xmlns:ex="http://example.com/ns" xmlns="http://default.example/"><ex:item/></root>', true);
xml_parser_free($p2);
echo json_encode($start2), "\n";
echo "done\n";
--EXPECT--
[["ex","http:\/\/example.com\/ns"]]
[]
ok
S:http://default.example/:root
S:http://example.com/ns:item
E:http://example.com/ns:item
E:http://default.example/:root
[["ex","http:\/\/example.com\/ns"],[false,"http:\/\/default.example\/"]]
done
