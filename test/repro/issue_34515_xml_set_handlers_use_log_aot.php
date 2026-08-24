<?php

declare(strict_types=1);

// #34515 — combined element + character-data Closures capturing &$log
$p = xml_parser_create();
$log = [];
xml_set_element_handler(
    $p,
    function ($parser, $name, $attrs) use (&$log) {
        $log[] = "S:$name";
    },
    function ($parser, $name) use (&$log) {
        $log[] = "E:$name";
    }
);
xml_set_character_data_handler($p, function ($parser, $data) use (&$log) {
    $log[] = "D:$data";
});
xml_parse($p, '<r>hi</r>', true);
echo implode('|', $log), "\n";
