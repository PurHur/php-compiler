<?php

declare(strict_types=1);

// DOMDocument::getElementById(null) under strict_types → TypeError (#29942).
$d = new DOMDocument();
$d->loadXML('<r id="x"/>');
$d->documentElement->setIdAttribute('id', true);
try {
    $d->getElementById(null);
    echo "fail:no_throw\n";
    exit(1);
} catch (TypeError $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
