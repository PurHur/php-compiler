--TEST--
stdlib libxml_get_errors() — unclosed start tag reports LIBXML_ERR_FATAL (#14396, ext/libxml/libxml.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$doc = new DOMDocument();
$doc->loadXML('<root><unclosed');
$errors = libxml_get_errors();
echo $errors[0]->level === LIBXML_ERR_FATAL ? "fatal\n" : "nofatal\n";
echo $errors[0]->code === 73 ? "code\n" : "nocode\n";
echo str_contains($errors[0]->message, "Couldn't find end of Start Tag unclosed") ? "msg\n" : "nomsg\n";
--EXPECT--
fatal
code
msg
