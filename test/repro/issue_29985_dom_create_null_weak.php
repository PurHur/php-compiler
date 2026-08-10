<?php

// Soft null: non-strict createElement(null) coerces to '' then Invalid Character Error (#29985).
$d = new DOMDocument();
try {
    $d->createElement(null);
    echo "fail:no_throw\n";
} catch (DOMException $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
