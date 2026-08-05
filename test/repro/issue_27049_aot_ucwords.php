<?php

/**
 * Issue #27049 — AOT ucwords() must print Zend output (not segfault).
 */
echo ucwords('hello world'), "\n";
echo ucwords('hello-world', '-'), "\n";
