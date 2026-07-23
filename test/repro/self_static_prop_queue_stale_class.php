<?php
/**
 * Issue #22642 / #22037 — self::$prop must bind to the method's declaring class when the
 * JIT queue previously lowered a similarly-named neighbour (InArrayJitHelper → IniJitHelper).
 *
 * AOT done-when: compile must not throw LogicException "Undefined static property: pcreJit"
 * (that was the Zend full-spine refresh failure). Runtime echoes ini values when autoload is complete.
 */
require_once __DIR__.'/../../ext/standard/VmIniIntrospection.php';
require_once __DIR__.'/../../ext/standard/VmIni.php';
require_once __DIR__.'/../../ext/standard/InArrayJitHelper.php';
require_once __DIR__.'/../../ext/standard/IniJitHelper.php';

echo \PHPCompiler\ext\standard\IniJitHelper::iniGet('pcre.jit'), "\n";
echo \PHPCompiler\ext\standard\IniJitHelper::iniGet('pcre.backtrack_limit'), "\n";
