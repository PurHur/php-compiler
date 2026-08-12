--TEST--
libxml null entity loader warning — clarified text on PROFILE=8.4 (#30424, ext/libxml/libxml.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$entFile = sys_get_temp_dir().'/phpc_dom_ext_ent_'.getmypid().'.txt';
file_put_contents($entFile, 'HELLO');
$xml = '<!DOCTYPE r [ <!ENTITY x SYSTEM "'.$entFile.'"> ]><r>&x;</r>';

libxml_use_internal_errors(true);
libxml_clear_errors();
$calls = 0;
libxml_set_external_entity_loader(function ($public, $system, $context) use (&$calls) {
    $calls++;
    return null;
});
$d = new DOMDocument();
$d->loadXML($xml, LIBXML_NOENT);
$errs = libxml_get_errors();
echo 'calls=', $calls, "\n";
echo 'msg=', $errs ? trim($errs[0]->message) : 'none', "\n";

libxml_set_external_entity_loader(null);
@unlink($entFile);
--EXPECT--
calls=1
msg=Failed to load external entity because the resolver function returned null
