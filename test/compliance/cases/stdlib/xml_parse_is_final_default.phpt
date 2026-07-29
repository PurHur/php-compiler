--TEST--
stdlib xml_parse() default is_final=false still fires SAX; chunked parse (#24647, ext/xml/xml.c)
--FILE--
<?php
function collect_parse(string $data, ?bool $isFinal = null): string
{
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
    $ok = null === $isFinal ? xml_parse($p, $data) : xml_parse($p, $data, $isFinal);
    xml_parser_free($p);

    return 'ok='.(int) $ok.' out='.implode(',', $out);
}

echo collect_parse('<r><a/></r>'), "\n";
echo collect_parse('<r><a/></r>', true), "\n";

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
// Non-final incomplete chunk must succeed and fire start tags already complete (#24657).
$c1 = xml_parse($p, '<r><a', false);
echo 'c1='.(int) $c1.' out='.implode(',', $out), "\n";
$c2 = xml_parse($p, '/></r>', true);
echo 'c2='.(int) $c2.' out='.implode(',', $out), "\n";
xml_parser_free($p);

// Hard error must fail even when is_final is false (Expat mismatch).
$p = xml_parser_create();
$bad = xml_parse($p, '<r></x>', false);
echo 'mismatch='.(int) $bad.' err='.xml_get_error_code($p), "\n";
xml_parser_free($p);
?>
--EXPECT--
ok=1 out=+R,+A,-A,-R
ok=1 out=+R,+A,-A,-R
c1=1 out=+R
c2=1 out=+R,+A,-A,-R
mismatch=0 err=76
