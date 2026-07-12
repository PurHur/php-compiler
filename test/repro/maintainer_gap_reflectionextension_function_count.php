<?php

declare(strict_types=1);

// #18357 — ReflectionExtension::getFunctions() must not expose __compiler_* or phantom builtins.

$re = new ReflectionExtension('standard');
$funcs = $re->getFunctions();
echo 'count=', count($funcs), "\n";
echo 'first=', array_key_first($funcs), "\n";
echo 'has_internal_compiler=', isset($funcs['__compiler_is_superglobal_name']) ? 'yes' : 'no', "\n";
