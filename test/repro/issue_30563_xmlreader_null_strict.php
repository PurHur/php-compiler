<?php

declare(strict_types=1);

/** Repro #30563 — XMLReader::XML/open(null) strict: TypeError with ", null given". */
error_reporting(E_ALL);

try {
    XMLReader::XML(null);
    echo "XML:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $r = new XMLReader();
    $r->open(null);
    echo "open:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
