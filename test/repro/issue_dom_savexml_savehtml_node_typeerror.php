<?php

/** #31396 — saveXML/saveHTML arg1 is ?DOMNode; lone int is not options. */
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');

foreach ([
    'saveXML_int' => static function () use ($d) {
        $d->saveXML(1);
    },
    'saveXML_libxml' => static function () use ($d) {
        $d->saveXML(LIBXML_NOEMPTYTAG);
    },
    'saveHTML_int' => static function () use ($d) {
        $d->saveHTML(1);
    },
    'saveXML_string' => static function () use ($d) {
        $d->saveXML('x');
    },
] as $label => $fn) {
    try {
        $fn();
        echo $label, "=accepted\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}

$ok = $d->saveXML(null, LIBXML_NOEMPTYTAG);
echo 'null_options=', (false !== strpos($ok, '<a></a>') ? 'ok' : 'bad'), "\n";
