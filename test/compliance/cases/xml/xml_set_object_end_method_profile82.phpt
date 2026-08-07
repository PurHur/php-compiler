--TEST--
ext/xml xml_set_object + string 'end' still uses object method under PROFILE≤8.2 (#28502)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
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
xml_parse($p, '<r><a/></r>', true);
echo implode(',', $h->e), "\n";
?>
--EXPECT--
S:R,S:A,E:A,E:R
