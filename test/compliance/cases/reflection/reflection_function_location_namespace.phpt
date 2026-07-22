--TEST--
Stdlib: ReflectionFunction location/namespace API (#22144)
--FILE--
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
    echo "ReflectionFunction::$m ", (int) method_exists($r, $m), "\n";
}
echo 'doc: ', var_export($r->getDocComment(), true), "\n";
echo 'file: ', var_export($r->getFileName(), true), "\n";
echo 'ns: ', var_export($r->getNamespaceName(), true), "\n";
echo 'short: ', var_export($r->getShortName(), true), "\n";
echo 'inNs: ', (int) $r->inNamespace(), "\n";
echo 'start: ', $r->getStartLine(), "\n";
echo 'end: ', $r->getEndLine(), "\n";

$ri = new \ReflectionFunction('strlen');
echo 'i_file: ', var_export($ri->getFileName(), true), "\n";
echo 'i_start: ', var_export($ri->getStartLine(), true), "\n";
echo 'i_end: ', var_export($ri->getEndLine(), true), "\n";
echo 'i_doc: ', var_export($ri->getDocComment(), true), "\n";
echo 'i_ns: ', var_export($ri->getNamespaceName(), true), "\n";
echo 'i_short: ', var_export($ri->getShortName(), true), "\n";
echo 'i_inNs: ', (int) $ri->inNamespace(), "\n";
--EXPECTF--
ReflectionFunction::getFileName 1
ReflectionFunction::getStartLine 1
ReflectionFunction::getEndLine 1
ReflectionFunction::getDocComment 1
ReflectionFunction::getNamespaceName 1
ReflectionFunction::getShortName 1
ReflectionFunction::inNamespace 1
doc: '/**
 * greet docs
 */'
file: '%s'
ns: 'Demo'
short: 'greet'
inNs: 1
start: %d
end: %d
i_file: false
i_start: false
i_end: false
i_doc: false
i_ns: ''
i_short: 'strlen'
i_inNs: 0
