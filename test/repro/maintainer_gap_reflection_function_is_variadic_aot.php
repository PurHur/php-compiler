<?php
/**
 * #22045 AOT-focused: ReflectionFunction::isVariadic() for free functions in-unit.
 */
function f($a, $b = 1, ...$c) {}
function g($a) {}
echo 'f_variadic=', (new ReflectionFunction('f'))->isVariadic() ? 'true' : 'false', "\n";
echo 'g_variadic=', (new ReflectionFunction('g'))->isVariadic() ? 'true' : 'false', "\n";
echo 'name=', (new ReflectionFunction('f'))->getName(), "\n";
echo 'call_user_func=', (new ReflectionFunction('call_user_func'))->isVariadic() ? 'true' : 'false', "\n";
echo 'strlen=', (new ReflectionFunction('strlen'))->isVariadic() ? 'true' : 'false', "\n";
