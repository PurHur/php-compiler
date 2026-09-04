<?php
// #36382 — simplexml_load_string non-literal under user-script AOT (Slim BodyParsingMiddleware).
// Soft-false so Composer graphs compile; literal fold still materializes SXE (#26863).
// php-src: ext/simplexml/simplexml.c PHP_FUNCTION(simplexml_load_string)
function load_xml(string $s) {
    $x = simplexml_load_string($s);

    return false === $x ? 'fail' : 'ok';
}
echo load_xml('<r>hi</r>'), PHP_EOL;
echo simplexml_load_string('<r>lit</r>') === false ? 'fail' : 'ok', PHP_EOL;
