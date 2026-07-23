<?php
/**
 * Repro for #22662 — ReflectionConstant file/ext/doc/inNamespace vs profile (php-src-strict).
 *
 * Zend stubs:
 * - PHP 8.4: no getFileName / getExtension* / getDocComment / inNamespace
 * - PHP 8.5: getFileName / getExtension / getExtensionName present; no getDocComment / inNamespace
 * - master (8.6+): + inNamespace (php/php-src#20902); still no getDocComment / getStartLine
 */
$r = new ReflectionConstant('PHP_VERSION');
$checks = [
    'getFileName',
    'getExtension',
    'getExtensionName',
    'getDocComment',
    'inNamespace',
    'getStartLine',
    'getEndLine',
];
foreach ($checks as $m) {
    echo $m, '=', method_exists($r, $m) ? 'yes' : 'no', "\n";
}
echo "ok\n";
