--TEST--
ext/xml xml_set_object(null) — TypeError; prior object+handlers kept (#22798, ext/xml/xml.c)
--FILE--
<?php
class H {
    public array $e = [];
    function s($p, $n, $a) { $this->e[] = $n; }
    function e($p, $n) {}
}
$h = new H();
$p = xml_parser_create();
xml_set_object($p, $h);
xml_set_element_handler($p, 's', 'e');
try {
    xml_set_object($p, null);
    echo "null_ok\n";
} catch (Throwable $ex) {
    echo get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    xml_set_object($p, 1);
    echo "int_ok\n";
} catch (Throwable $ex) {
    echo get_class($ex), ':', $ex->getMessage(), "\n";
}
xml_parse($p, '<r/>', true);
echo 'els=', implode(',', $h->e), "\n";
--EXPECT--
TypeError:xml_set_object(): Argument #2 ($object) must be of type object, null given
TypeError:xml_set_object(): Argument #2 ($object) must be of type object, int given
els=R
