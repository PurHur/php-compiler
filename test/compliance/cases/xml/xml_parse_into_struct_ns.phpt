--TEST--
xml_parse_into_struct() expands xml_parser_create_ns names + CASE_FOLDING (#21631, ext/xml/xml.c)
--FILE--
<?php
function tags_of(array $vals): array {
    $tags = [];
    foreach ($vals as $v) {
        $tags[] = $v['tag'];
    }
    return $tags;
}

$p = xml_parser_create_ns();
$vals = [];
xml_parse_into_struct($p, '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>', $vals);
xml_parser_free($p);
echo 'tags='.implode(',', tags_of($vals))."\n";
$rootKeys = [];
foreach ($vals[0] as $k => $_) {
    $rootKeys[] = $k;
}
echo 'root_keys='.implode(',', $rootKeys)."\n";
$childAttr = '';
foreach ($vals[1] as $k => $v) {
    if ($k === 'attributes') {
        foreach ($v as $ak => $av) {
            $childAttr .= $ak.'='.$av.';';
        }
    }
}
echo 'child_attrs='.$childAttr."\n";

$p2 = xml_parser_create_ns();
xml_parser_set_option($p2, XML_OPTION_CASE_FOLDING, 0);
$vals2 = [];
xml_parse_into_struct($p2, '<n:r xmlns:n="urn:x"><n:a/></n:r>', $vals2);
xml_parser_free($p2);
echo 'nofold='.implode(',', tags_of($vals2))."\n";

$p3 = xml_parser_create_ns(null, ' ');
$vals3 = [];
xml_parse_into_struct($p3, '<n:r xmlns:n="urn:x"><n:a/></n:r>', $vals3);
xml_parser_free($p3);
echo 'space='.implode(',', tags_of($vals3))."\n";

$p4 = xml_parser_create_ns();
$vals4 = [];
xml_parse_into_struct($p4, '<r xmlns="urn:d"><a/></r>', $vals4);
xml_parser_free($p4);
echo 'default_ns='.implode(',', tags_of($vals4))."\n";

$p5 = xml_parser_create();
xml_parser_set_option($p5, XML_OPTION_CASE_FOLDING, 0);
$vals5 = [];
xml_parse_into_struct($p5, '<Root Attr="1"/>', $vals5);
xml_parser_free($p5);
$plainAttr = '';
foreach ($vals5[0] as $k => $v) {
    if ($k === 'attributes') {
        foreach ($v as $ak => $av) {
            $plainAttr .= $ak.'='.$av;
        }
    }
}
echo 'plain='.$vals5[0]['tag'].':'.$plainAttr."\n";
echo "done\n";
--EXPECT--
tags=URN:X:R,URN:X:A,URN:X:R
root_keys=tag,type,level
child_attrs=B=1;
nofold=urn:x:r,urn:x:a,urn:x:r
space=URN:X R,URN:X A,URN:X R
default_ns=URN:D:R,URN:D:A,URN:D:R
plain=Root:Attr=1
done
