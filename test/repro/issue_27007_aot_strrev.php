<?php

/**
 * Issue #27007 — AOT strrev() must print Zend output (not segfault).
 */
echo strrev('ab'), "\n";
echo strrev('php-compiler'), "\n";
