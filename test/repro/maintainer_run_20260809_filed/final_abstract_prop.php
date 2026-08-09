<?php
// Repro for #29424 — Zend Fatal: Cannot use the final modifier on an abstract property
abstract class A {
    final abstract public string $x { get; }
}
echo "parsed\n";
