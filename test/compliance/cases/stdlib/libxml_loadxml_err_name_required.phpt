--TEST--
stdlib libxml_get_last_error code/message for bare '<' loadXML (#22655, XML_ERR_NAME_REQUIRED)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$d = new DOMDocument();
$d->loadXML('<');
$e = libxml_get_last_error();
echo trim($e->message), "\n";
echo $e->code, "\n";
?>
--EXPECT--
StartTag: invalid element name
68
