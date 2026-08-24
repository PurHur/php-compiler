<?php

declare(strict_types=1);

// #34378 — AOT xml_parse_into_struct (php-src ext/xml/xml.c)
$p = xml_parser_create();
$vals = [];
$idx = [];
$status = xml_parse_into_struct($p, '<a><b/></a>', $vals, $idx);
echo $status, "\n";
echo count($vals), "\n";
echo (int) array_key_exists('B', $idx), "\n";
echo $vals[1]['tag'], ':', $vals[1]['type'], "\n";
