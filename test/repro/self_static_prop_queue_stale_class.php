<?php
/**
 * Issue #22642 / #22037 — self::$prop must bind to the method's declaring class when the
 * JIT queue previously lowered a similarly-named neighbour (InArrayJitHelper → IniJitHelper).
 */
require_once __DIR__.'/../../ext/standard/InArrayJitHelper.php';
require_once __DIR__.'/../../ext/standard/IniJitHelper.php';

echo \PHPCompiler\ext\standard\IniJitHelper::iniGet('pcre.jit'), "\n";
echo \PHPCompiler\ext\standard\IniJitHelper::iniGet('pcre.backtrack_limit'), "\n";
