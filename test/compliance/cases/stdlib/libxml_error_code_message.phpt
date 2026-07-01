--TEST--
stdlib libxml_get_errors code/message for premature end tag (#14467, ext/libxml/libxml.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$d = new DOMDocument();
@$d->loadXML('<bad>');
$e = libxml_get_errors()[0];
echo $e->code, "\n";
echo trim($e->message), "\n";
?>
--EXPECT--
77
Premature end of data in tag bad line 1
