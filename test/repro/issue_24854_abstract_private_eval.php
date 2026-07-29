<?php
/**
 * #24854 — abstract private via eval must be Zend compile fatal, not internal TypeError.
 *
 * Expect (matches Zend): Fatal error: Abstract function A::f() cannot be declared private
 * Exit 255.
 */
eval('abstract class A { abstract private function f(); }');
echo "NO_FATAL\n";
