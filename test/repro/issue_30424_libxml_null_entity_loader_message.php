<?php

declare(strict_types=1);

// libxml null entity loader warning text — profile gate (#30424).
$entFile = sys_get_temp_dir().'/phpc_dom_ext_ent_'.getmypid().'.txt';
file_put_contents($entFile, 'HELLO');
$xml = '<!DOCTYPE r [ <!ENTITY x SYSTEM "'.$entFile.'"> ]><r>&x;</r>';

libxml_use_internal_errors(true);
libxml_clear_errors();
$calls = 0;
libxml_set_external_entity_loader(static function ($public, $system, $context) use (&$calls) {
    ++$calls;
    unset($public, $system, $context);

    return null;
});
$d = new DOMDocument();
$d->loadXML($xml, LIBXML_NOENT);
$errs = libxml_get_errors();
echo 'calls=', $calls, "\n";
echo 'msg=', $errs ? trim($errs[0]->message) : 'none', "\n";

libxml_set_external_entity_loader(null);
@unlink($entFile);
