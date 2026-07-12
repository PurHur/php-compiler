<?php

declare(strict_types=1);

$p = xml_parser_create();
echo is_object($p) ? get_class($p) : gettype($p);
echo "\n";

$ok = xml_parse($p, '<a/>', true);
echo 'parse=', var_export($ok, true), "\n";
echo 'free=', var_export(xml_parser_free($p), true), "\n";
