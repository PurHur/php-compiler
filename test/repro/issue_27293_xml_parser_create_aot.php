<?php

declare(strict_types=1);

// #27293 — AOT xml_parser_create / xml_parse / xml_get_error_code (php-src ext/xml/xml.c)
$p = xml_parser_create();
xml_parse($p, '<root>x</root>');
echo xml_get_error_code($p) === 0 ? "ok\n" : "err\n";
