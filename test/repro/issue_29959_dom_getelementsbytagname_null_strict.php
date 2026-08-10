<?php

declare(strict_types=1);

// DOMDocument::getElementsByTagName(null) under strict_types → TypeError (#29959).
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
try {
    $d->getElementsByTagName(null);
    echo "fail:no_throw\n";
    exit(1);
} catch (TypeError $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
