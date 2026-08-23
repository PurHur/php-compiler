<?php
/**
 * AOT: TypeError from DOMDocument::saveXML(int) inside a closure must be catchable
 * by an outer try/catch (Zend / ext/dom/php_dom.stub.php ?DOMNode $node).
 *
 * Peer #33971 (appendChild null in closure). Direct try/catch in {main} is green;
 * foreach+closure+try/catch repro: test/repro/issue_dom_savexml_savehtml_node_typeerror.php.
 */
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$fn = static function () use ($d) {
    $d->saveXML(1);
};
try {
    $fn();
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
