<?php

declare(strict_types=1);

/**
 * AOT DOM mutation/importNode(null) must TypeError, not SIGSEGV (#32558, leftover of #30410).
 * php-src ext/dom/node.c Z_PARAM_OBJ_OF_CLASS / document.c importNode.
 *
 * Sequential try/catch (not closures): thin-AOT does not unwind TypeError out of
 * a compiled closure into the caller catch.
 */
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$e = $d->documentElement;

try {
    $d->appendChild(null);
    echo "docAppendChild=fail:no_throw\n";
    exit(1);
} catch (TypeError $ex) {
    echo 'docAppendChild=', $ex->getMessage(), "\n";
}

try {
    $e->appendChild(null);
    echo "appendChild=fail:no_throw\n";
    exit(1);
} catch (TypeError $ex) {
    echo 'appendChild=', $ex->getMessage(), "\n";
}

try {
    $e->insertBefore(null);
    echo "insertBefore=fail:no_throw\n";
    exit(1);
} catch (TypeError $ex) {
    echo 'insertBefore=', $ex->getMessage(), "\n";
}

try {
    $e->replaceChild(null, $e->firstChild);
    echo "replaceChild=fail:no_throw\n";
    exit(1);
} catch (TypeError $ex) {
    echo 'replaceChild=', $ex->getMessage(), "\n";
}

try {
    $e->removeChild(null);
    echo "removeChild=fail:no_throw\n";
    exit(1);
} catch (TypeError $ex) {
    echo 'removeChild=', $ex->getMessage(), "\n";
}

try {
    $d->importNode(null);
    echo "importNode=fail:no_throw\n";
    exit(1);
} catch (TypeError $ex) {
    echo 'importNode=', $ex->getMessage(), "\n";
}
