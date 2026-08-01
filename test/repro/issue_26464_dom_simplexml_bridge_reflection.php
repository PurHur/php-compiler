<?php
declare(strict_types=1);

/**
 * Repro #26464 — dom_import_simplexml / simplexml_import_dom Reflection stubs.
 * php-src: ext/dom/php_dom.stub.php, ext/simplexml/simplexml.stub.php
 */
foreach (['dom_import_simplexml', 'simplexml_import_dom'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, PHP_EOL;
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), ':', $p->getType() ? (string) $p->getType() : '<none>';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo PHP_EOL;
    }
    echo '  return:', $r->getReturnType() ? (string) $r->getReturnType() : '<none>', PHP_EOL;
}
