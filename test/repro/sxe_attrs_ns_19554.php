<?php
$x = simplexml_load_string('<r xmlns:p="urn:p" p:a="1" b="2"/>');
$a = $x->attributes('urn:p');
echo 'ns_attrs=', count($a), "\n";
foreach ($a as $k => $v) {
    echo "ns $k=$v\n";
}
$a2 = $x->attributes();
echo 'plain_attrs=', count($a2), "\n";
foreach ($a2 as $k => $v) {
    echo "plain $k=$v\n";
}
