<?php

/**
 * AOT: lookupPrefix(null) under declare(strict_types=1) → TypeError (#34099).
 */
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root xmlns:foo="http://example.com/foo"/>');
$root = $doc->documentElement;
try {
    $root->lookupPrefix(null);
    echo "lookupPrefix=fail:no_throw\n";
} catch (TypeError $ex) {
    echo 'lookupPrefix=', $ex->getMessage(), "\n";
}
try {
    $root->isDefaultNamespace(null);
    echo "isDefaultNamespace=fail:no_throw\n";
} catch (TypeError $ex) {
    echo 'isDefaultNamespace=', $ex->getMessage(), "\n";
}
