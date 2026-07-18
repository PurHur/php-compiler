--TEST--
ext/xml xml_set_default_handler() — comments, PIs, unhandled markup (#20333)
--FILE--
<?php
function parse_default(string $xml, bool $withElement = false): array
{
    $out = [];
    $p = xml_parser_create();
    xml_set_default_handler($p, function ($parser, $data) use (&$out) {
        $out[] = $data;
    });
    if ($withElement) {
        xml_set_element_handler(
            $p,
            function ($parser, $name, $attrs) {},
            function ($parser, $name) {}
        );
    }
    xml_parse($p, $xml, true);
    xml_parser_free($p);

    return $out;
}

echo json_encode(parse_default('<!--c--><r/>')), "\n";
echo json_encode(parse_default('<?pi data?><r/>')), "\n";
echo json_encode(parse_default('<!--c--><r/>', true)), "\n";
echo json_encode(parse_default('<r><!--c--></r>')), "\n";
echo json_encode(parse_default('<r/>')), "\n";

$out = [];
$p = xml_parser_create();
xml_set_default_handler($p, function ($parser, $data) use (&$out) {
    $out[] = $data;
});
xml_set_processing_instruction_handler($p, function ($parser, $target, $data) use (&$out) {
    $out[] = 'PI:'.$target.':'.$data;
});
xml_parse($p, '<?pi data?><r/>', true);
xml_parser_free($p);
echo json_encode($out), "\n";

$out = [];
$p = xml_parser_create();
xml_set_default_handler($p, function ($parser, $data) use (&$out) {
    $out[] = $data;
});
xml_set_character_data_handler($p, function ($parser, $data) use (&$out) {
    $out[] = 'CDATA:'.$data;
});
xml_parse($p, '<r>x</r>', true);
xml_parser_free($p);
echo json_encode($out), "\n";

echo "done\n";
--EXPECT--
["<!--c-->","<r>","<\/r>"]
["<?pi data?>","<r>","<\/r>"]
["<!--c-->"]
["<r>","<!--c-->","<\/r>"]
["<r>","<\/r>"]
["PI:pi:data","<r>","<\/r>"]
["<r>","CDATA:x","<\/r>"]
done
