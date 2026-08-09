<?php

declare(strict_types=1);

// #29318 — AOT xml_parser_free after create/parse (php-src ext/xml/xml.c; no-op since 8.0)
$p = xml_parser_create();
$ok = xml_parse($p, '<r></r>', true);
echo 'parse=', $ok ? 'ok' : 'fail', "\n";
$freed = xml_parser_free($p);
echo 'free=', $freed ? 'true' : 'false', "\n";
$freed2 = xml_parser_free($p);
echo 'free2=', $freed2 ? 'true' : 'false', "\n";
// After is_final=true, further parse fails on Zend too — free must not crash / TypeError.
$ok2 = xml_parse($p, '<r/>', true);
echo 'parse2=', $ok2 ? 'ok' : 'fail', "\n";
