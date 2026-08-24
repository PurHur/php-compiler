<?php

declare(strict_types=1);

// #34487 — AOT xml_set_character_data_handler Closure SAX (php-src ext/xml/xml.c)
$p = xml_parser_create();
xml_set_character_data_handler($p, function ($parser, $data) {
    echo "D:$data\n";
});
xml_parse($p, '<r>hi</r>', true);
xml_parser_free($p);
