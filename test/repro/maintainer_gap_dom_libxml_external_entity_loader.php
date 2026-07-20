<?php

declare(strict_types=1);

/**
 * #21599 — SYSTEM general entities + libxml_set_external_entity_loader on loadXML(LIBXML_NOENT).
 *
 * php-src: ext/dom/document.c + ext/libxml/libxml.c
 */
$entFile = sys_get_temp_dir().'/phpc_dom_ext_ent_'.getmypid().'.txt';
file_put_contents($entFile, 'HELLO');

$xml = '<!DOCTYPE r [ <!ENTITY x SYSTEM "'.$entFile.'"> ]><r>&x;</r>';

libxml_use_internal_errors(true);
libxml_clear_errors();

$d = new DOMDocument();
$d->loadXML($xml);
$fc = $d->documentElement->firstChild;
echo 'noent0_class='.($fc ? get_class($fc) : 'none')."\n";
echo 'noent0_name='.($fc ? $fc->nodeName : '')."\n";
echo 'noent0_xml='.$d->saveXML($d->documentElement)."\n";

libxml_clear_errors();
$d2 = new DOMDocument();
$d2->loadXML($xml, LIBXML_NOENT);
$fc2 = $d2->documentElement->firstChild;
echo 'default_class='.($fc2 ? get_class($fc2) : 'none')."\n";
echo 'default_val='.($fc2 ? $fc2->nodeValue : '')."\n";
echo 'default_xml='.$d2->saveXML($d2->documentElement)."\n";
$errs2 = libxml_get_errors();
echo 'default_err='.(is_array($errs2) ? count($errs2) : -1)."\n";

libxml_clear_errors();
$calls = 0;
libxml_set_external_entity_loader(static function ($public, $system, $context) use (&$calls) {
    ++$calls;
    unset($public, $system, $context);

    return null;
});
$d3 = new DOMDocument();
$d3->loadXML($xml, LIBXML_NOENT);
echo 'null_loader_calls='.$calls."\n";
echo 'null_loader_xml='.$d3->saveXML($d3->documentElement)."\n";
$errs3 = libxml_get_errors();
echo 'null_loader_err='.(is_array($errs3) ? count($errs3) : -1)."\n";
foreach ($errs3 as $e) {
    echo 'null_loader_msg='.trim($e->message)."\n";
    echo 'null_loader_code='.$e->code."\n";
    echo 'null_loader_level='.$e->level."\n";
}

libxml_clear_errors();
libxml_set_external_entity_loader(static function ($public, $system, $context) use ($entFile) {
    unset($public, $system, $context);

    return $entFile;
});
$d4 = new DOMDocument();
$d4->loadXML($xml, LIBXML_NOENT);
$fc4 = $d4->documentElement->firstChild;
echo 'path_loader_val='.($fc4 ? $fc4->nodeValue : '')."\n";

@unlink($entFile);
