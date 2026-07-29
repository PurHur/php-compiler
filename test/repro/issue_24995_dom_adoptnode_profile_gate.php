<?php

declare(strict_types=1);

/**
 * #24995 — DOMDocument::adoptNode() NYI on reference 8.2 profile; real adopt on PROFILE≥8.3.
 *
 * Default (unset PROFILE):
 *   php bin/vm.php test/repro/issue_24995_dom_adoptnode_profile_gate.php
 * Forward 8.3:
 *   PHP_COMPILER_PROFILE=8.3 php bin/vm.php test/repro/issue_24995_dom_adoptnode_profile_gate.php
 */

$a = new DOMDocument();
$a->loadXML('<r><x/></r>');
$b = new DOMDocument();
echo 'method=', (int) method_exists($b, 'adoptNode'), "\n";
try {
    $n = $b->adoptNode($a->documentElement->firstChild);
    echo 'ok:', $n->nodeName, "\n";
    echo 'source_children=', $a->documentElement->childNodes->length, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
