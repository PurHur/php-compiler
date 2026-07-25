--TEST--
simplexml undeclared entity — Exception + libxml error 26 (#22775, ext/simplexml/sxe.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();

try {
    $x = new SimpleXMLElement('<r>&foo;</r>');
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
$errors = libxml_get_errors();
echo count($errors), "\n";
echo $errors[0]->code, "\n";
echo $errors[0]->level, "\n";
echo trim($errors[0]->message), "\n";
echo $errors[0]->column, "\n";

libxml_clear_errors();
$loaded = @simplexml_load_string('<r a="&bar;">x</r>');
echo ($loaded === false) ? "false\n" : "loaded\n";
$e2 = libxml_get_errors();
echo $e2[0]->code, "\n";
echo trim($e2[0]->message), "\n";

libxml_clear_errors();
try {
    $ok = new SimpleXMLElement('<r>&amp;</r>');
    echo "predef_ok\n";
    echo (false !== strpos($ok->asXML(), '<r>')) ? "has_root\n" : "no_root\n";
} catch (Throwable $e) {
    echo "predef_fail\n";
}
--EXPECT--
Exception
String could not be parsed as XML
1
26
3
Entity 'foo' not defined
9
false
26
Entity 'bar' not defined
predef_ok
has_root
