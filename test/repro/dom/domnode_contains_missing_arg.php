<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');

$root = $doc->documentElement;

try {
    $root->contains();
    echo "NO_EXCEPTION\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

