<?php

declare(strict_types=1);

// DOMNode mutation / importNode null TypeError text (#30410).
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$e = $d->documentElement;
$cases = [
    'appendChild' => static function () use ($e) {
        $e->appendChild(null);
    },
    'insertBefore' => static function () use ($e) {
        $e->insertBefore(null);
    },
    'replaceChild' => static function () use ($e) {
        $e->replaceChild(null, $e->firstChild);
    },
    'removeChild' => static function () use ($e) {
        $e->removeChild(null);
    },
    'importNode' => static function () use ($d) {
        $d->importNode(null);
    },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, "=fail:no_throw\n";
        exit(1);
    } catch (TypeError $ex) {
        echo $name, '=', $ex->getMessage(), "\n";
    }
}
