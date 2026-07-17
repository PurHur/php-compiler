<?php
/** Repro #19343 — inline Closure SAX handlers must match Zend event stream. */
$events = [];
$p = xml_parser_create();
xml_set_element_handler(
    $p,
    function ($parser, $name, $attrs) use (&$events) { $events[] = "S:$name"; },
    function ($parser, $name) use (&$events) { $events[] = "E:$name"; }
);
try {
    xml_parse($p, '<root><a/></root>', true);
    echo implode(',', $events), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
