--TEST--
DOMDocument::loadXML SYSTEM entities + libxml_set_external_entity_loader (#21599, ext/dom + ext/libxml)
--FILE--
<?php
$entFile = sys_get_temp_dir().'/phpc_dom_ext_ent_'.getmypid().'.txt';
file_put_contents($entFile, 'HELLO');
$xml = '<!DOCTYPE r [ <!ENTITY x SYSTEM "'.$entFile.'"> ]><r>&x;</r>';

libxml_use_internal_errors(true);

$d = new DOMDocument();
$d->loadXML($xml);
$c = $d->documentElement->firstChild;
echo 'no_noent=', get_class($c), '|', $c->nodeName, "\n";

libxml_clear_errors();
$d2 = new DOMDocument();
$d2->loadXML($xml, LIBXML_NOENT);
$c2 = $d2->documentElement->firstChild;
echo 'default=', get_class($c2), '|', $c2->nodeValue, '|', $d2->saveXML($d2->documentElement), "\n";

libxml_clear_errors();
$calls = 0;
libxml_set_external_entity_loader(function ($public, $system, $context) use (&$calls) {
    $calls++;
    return null;
});
$d3 = new DOMDocument();
$d3->loadXML($xml, LIBXML_NOENT);
$errs = libxml_get_errors();
echo 'null_loader=', $calls, '|', $d3->saveXML($d3->documentElement), '|', count($errs);
if ($errs) {
    echo '|', trim($errs[0]->message), '|', $errs[0]->code, '|', $errs[0]->level;
}
echo "\n";

libxml_clear_errors();
libxml_set_external_entity_loader(function ($public, $system, $context) use ($entFile) {
    return $entFile;
});
$d4 = new DOMDocument();
$d4->loadXML($xml, LIBXML_NOENT);
echo 'path_loader=', $d4->documentElement->firstChild->nodeValue, "\n";

libxml_set_external_entity_loader(null);
@unlink($entFile);
--EXPECT--
no_noent=DOMEntityReference|x
default=DOMText|HELLO|<r>HELLO</r>
null_loader=1|<r/>|1|Failed to load external entity "NULL"|1|2
path_loader=HELLO
