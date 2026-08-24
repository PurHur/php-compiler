<?php

declare(strict_types=1);

// #34454 — AOT simplexml_load_file with compile-time path (php-src ext/simplexml/simplexml.c)
// Path is repo-relative; compile/run from repo root (docker-exec cwd=/compiler).
$x = simplexml_load_file('test/repro/fixtures/simplexml_load_file_aot_34454.xml');
echo $x->getName(), ':', (string) $x['a'], "\n";
echo "DONE\n";
