--TEST--
ext/xml xml_set_object + string 'end' prefers global end() under PROFILE≥8.4 (#28502, ext/xml/xml.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
class H
{
    public array $e = [];
    public function start($p, $name, $attrs)
    {
        $this->e[] = "S:$name";
    }
    public function end($p, $name)
    {
        $this->e[] = "E:$name";
    }
}
$h = new H();
$p = xml_parser_create();
xml_set_object($p, $h);
xml_set_element_handler($p, 'start', 'end');
try {
    xml_parse($p, '<r><a/></r>', true);
    echo 'events=', implode(',', $h->e), "\n";
} catch (Throwable $ex) {
    echo get_class($ex), "\n";
}
// Proper callable array still uses the method (#28502).
$h2 = new H();
$p2 = xml_parser_create();
xml_set_element_handler($p2, [$h2, 'start'], [$h2, 'end']);
xml_parse($p2, '<r><a/></r>', true);
echo 'callable=', implode(',', $h2->e), "\n";
// Non-colliding method name still binds via xml_set_object.
class H2
{
    public array $e = [];
    public function start($p, $name, $attrs)
    {
        $this->e[] = "S:$name";
    }
    public function finish($p, $name)
    {
        $this->e[] = "E:$name";
    }
}
$h3 = new H2();
$p3 = xml_parser_create();
xml_set_object($p3, $h3);
xml_set_element_handler($p3, 'start', 'finish');
xml_parse($p3, '<r><a/></r>', true);
echo 'finish=', implode(',', $h3->e), "\n";
?>
--EXPECT--
ArgumentCountError
callable=S:R,S:A,E:A,E:R
finish=S:R,S:A,E:A,E:R
