<?php

declare(strict_types=1);

// #34487 — AOT xml_set_element_handler Closure SAX (php-src ext/xml/xml.c)
$p = xml_parser_create();
xml_set_element_handler(
    $p,
    function ($parser, $name, $attrs) {
        echo "S:$name\n";
    },
    function ($parser, $name) {
        echo "E:$name\n";
    }
);
xml_parse($p, '<r><c/></r>', true);
xml_parser_free($p);
