<?php
namespace Demo;

/**
 * greet docs
 */
function greet($x) {
    return $x;
}

$r = new \ReflectionFunction('Demo\\greet');
foreach (['getFileName', 'getStartLine', 'getEndLine', 'getDocComment', 'getNamespaceName', 'getShortName', 'inNamespace'] as $m) {
    echo $m, '=', (int) method_exists($r, $m), "\n";
}
echo 'file=', var_export($r->getFileName(), true), "\n";
echo 'start=', var_export($r->getStartLine(), true), "\n";
echo 'end=', var_export($r->getEndLine(), true), "\n";
echo 'doc=', var_export($r->getDocComment(), true), "\n";
echo 'ns=', var_export($r->getNamespaceName(), true), "\n";
echo 'short=', var_export($r->getShortName(), true), "\n";
echo 'inNs=', var_export($r->inNamespace(), true), "\n";

$ri = new \ReflectionFunction('strlen');
echo 'i_file=', var_export($ri->getFileName(), true), "\n";
echo 'i_start=', var_export($ri->getStartLine(), true), "\n";
echo 'i_end=', var_export($ri->getEndLine(), true), "\n";
echo 'i_doc=', var_export($ri->getDocComment(), true), "\n";
echo 'i_ns=', var_export($ri->getNamespaceName(), true), "\n";
echo 'i_short=', var_export($ri->getShortName(), true), "\n";
echo 'i_inNs=', var_export($ri->inNamespace(), true), "\n";
