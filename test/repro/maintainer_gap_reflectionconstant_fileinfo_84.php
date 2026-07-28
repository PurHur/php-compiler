<?php
/**
 * Repro for #23894 / #22662 — PROFILE=8.4 ReflectionConstant surface (php-src-strict).
 *
 * php-src PHP-8.4 stubs: ReflectionConstant has getName/getValue/… only —
 * getFileName / getExtension* / getDocComment / inNamespace / getStartLine / getEndLine
 * are absent (file/ext land in 8.5; inNamespace in 8.6+; doc/line never).
 *
 * Expected on PROFILE=8.4: all listed methods MISSING (not a regression).
 */
const ANSWER = 42;
$r = new ReflectionConstant('ANSWER');
foreach (['getFileName', 'getStartLine', 'getEndLine', 'getDocComment', 'getExtension', 'getExtensionName', 'inNamespace'] as $m) {
    echo $m, '=', method_exists($r, $m) ? 'yes' : 'MISSING', "\n";
}
