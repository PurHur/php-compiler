--TEST--
stdlib xml_parse() non-final chunks fire start-element SAX when start tag complete (#24657, ext/xml/xml.c)
--FILE--
<?php
$p = xml_parser_create();
$out = [];
xml_set_element_handler(
    $p,
    function ($parser, $name, $attrs) use (&$out) {
        $out[] = '+'.$name;
    },
    function ($parser, $name) use (&$out) {
        $out[] = '-'.$name;
    }
);
$c1 = xml_parse($p, '<r><a', false);
echo 'c1='.(int) $c1.' out='.implode(',', $out), "\n";
$c2 = xml_parse($p, '/></r>', true);
echo 'c2='.(int) $c2.' out='.implode(',', $out), "\n";
xml_parser_free($p);

// Character data coalesces across chunks until markup (libxml-compat Zend).
$p = xml_parser_create();
$log = [];
xml_set_element_handler(
    $p,
    function ($parser, $name, $attrs) use (&$log) {
        $log[] = '+'.$name;
    },
    function ($parser, $name) use (&$log) {
        $log[] = '-'.$name;
    }
);
xml_set_character_data_handler($p, function ($parser, $data) use (&$log) {
    $log[] = 'T:'.$data;
});
xml_parse($p, '<r>hel', false);
echo 'mid='.implode(',', $log), "\n";
xml_parse($p, 'lo</r>', true);
echo 'done='.implode(',', $log), "\n";
xml_parser_free($p);
?>
--EXPECT--
c1=1 out=+R
c2=1 out=+R,+A,-A,-R
mid=+R
done=+R,T:hello,-R
