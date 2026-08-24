<?php

declare(strict_types=1);

// #34515 — AOT xml_set_element_handler Closures with use() (php-src ext/xml/xml.c)
// NestedClosureInvoke wrong-target: end event must not call the 3-arg start body.
$x = 1;
$p = xml_parser_create();
xml_set_element_handler(
    $p,
    function ($parser, $name, $attrs) use ($x) {
        echo "S:$name\n";
    },
    function ($parser, $name) use ($x) {
        echo "E:$name\n";
    }
);
xml_parse($p, '<r><c/></r>', true);
xml_parser_free($p);
