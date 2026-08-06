<?php
/**
 * #28317 AOT probe — uncaught excess argc (AOT try/catch+ACE still hits known
 * "terminator mid-block" verify failure; peer htmlspecialchars #28285 same).
 * Expect: ArgumentCountError with Zend message, non-zero exit.
 */
strtolower('A', 'x');
