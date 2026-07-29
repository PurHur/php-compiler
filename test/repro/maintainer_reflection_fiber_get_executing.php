<?php
/**
 * Issue #25058 — ReflectionFiber::getExecutingFiber is not in php-src.
 * Run: php bin/vm.php test/repro/maintainer_reflection_fiber_get_executing.php
 */
echo 'method=', method_exists('ReflectionFiber', 'getExecutingFiber') ? '1' : '0', "\n";
