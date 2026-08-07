<?php
/**
 * Repro #28502 — PROFILE≥8.4 string handler 'end' must resolve to global end(), not $obj->end.
 *
 * php-src: ext/xml/xml.c xml_set_element_handler (OF!F! before OSS method path)
 */
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
