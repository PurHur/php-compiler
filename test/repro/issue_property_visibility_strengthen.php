<?php
// Issue #25661 — Zend forbids strengthening property visibility on override.
class A { public $x = 1; }
class B extends A { protected $x = 1; }
echo "LOADED\n";
