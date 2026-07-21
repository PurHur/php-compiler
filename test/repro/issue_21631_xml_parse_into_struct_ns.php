<?php
/**
 * #21631 — xml_parse_into_struct() must expand xml_parser_create_ns names like Zend.
 * php-src: ext/xml/xml.c
 */
function tags_of(array $vals): array
{
    $tags = [];
    foreach ($vals as $v) {
        $tags[] = $v['tag'];
    }

    return $tags;
}

function attr_bag(array $entry): array
{
    $out = [];
    foreach ($entry as $k => $v) {
        if ($k === 'attributes') {
            foreach ($v as $ak => $av) {
                $out[$ak] = $av;
            }
        }
    }

    return $out;
}

$p = xml_parser_create_ns();
$vals = [];
xml_parse_into_struct($p, '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>', $vals);
xml_parser_free($p);

$tags = tags_of($vals);
$attrs0 = attr_bag($vals[0]);
$attrs1 = attr_bag($vals[1]);

echo 'tags='.implode(',', $tags)."\n";
echo 'root_attrs='.json_encode($attrs0)."\n";
echo 'child_attrs='.json_encode($attrs1)."\n";

$p2 = xml_parser_create_ns();
xml_parser_set_option($p2, XML_OPTION_CASE_FOLDING, 0);
$vals2 = [];
xml_parse_into_struct($p2, '<n:r xmlns:n="urn:x"><n:a/></n:r>', $vals2);
xml_parser_free($p2);
echo 'nofold='.implode(',', tags_of($vals2))."\n";

$p3 = xml_parser_create();
xml_parser_set_option($p3, XML_OPTION_CASE_FOLDING, 0);
$vals3 = [];
xml_parse_into_struct($p3, '<Root Attr="1"/>', $vals3);
xml_parser_free($p3);
$plainAttrs = attr_bag($vals3[0]);
$plainAttr = array_key_exists('Attr', $plainAttrs) ? $plainAttrs['Attr'] : '?';
echo 'plain_nofold='.$vals3[0]['tag'].','.$plainAttr."\n";

$p4 = xml_parser_create_ns(null, ' ');
$vals4 = [];
xml_parse_into_struct($p4, '<n:r xmlns:n="urn:x"><n:a/></n:r>', $vals4);
xml_parser_free($p4);
echo 'space_sep='.implode(',', tags_of($vals4))."\n";

$childB = array_key_exists('B', $attrs1) ? $attrs1['B'] : null;
$ok = $tags === ['URN:X:R', 'URN:X:A', 'URN:X:R']
    && $attrs0 === []
    && $childB === '1'
    && !array_key_exists('XMLNS:N', $attrs0)
    && tags_of($vals2) === ['urn:x:r', 'urn:x:a', 'urn:x:r']
    && $vals3[0]['tag'] === 'Root'
    && $plainAttr === '1'
    && tags_of($vals4) === ['URN:X R', 'URN:X A', 'URN:X R'];
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
