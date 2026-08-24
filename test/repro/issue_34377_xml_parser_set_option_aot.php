<?php

declare(strict_types=1);

// #34377 — AOT xml_parser_set_option / get_option (php-src ext/xml/xml.c; peer #27293/#29318)
$p = xml_parser_create();
$set = xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
echo 'set=', $set ? 'true' : 'false', "\n";
$fold = xml_parser_get_option($p, XML_OPTION_CASE_FOLDING);
echo 'fold=', var_export($fold, true), "\n";
$setEnc = xml_parser_set_option($p, XML_OPTION_TARGET_ENCODING, 'UTF-8');
echo 'setEnc=', $setEnc ? 'true' : 'false', "\n";
$enc = xml_parser_get_option($p, XML_OPTION_TARGET_ENCODING);
echo 'enc=', var_export($enc, true), "\n";
$ok = xml_parse($p, '<r><a/></r>', true);
echo 'parse=', $ok ? 'ok' : 'fail', "\n";
xml_parser_free($p);
echo "DONE\n";
