<?php
// Repro guard for #28373 — concrete subclass must implement abstract hooked properties.
abstract class A {
    abstract public string $x { get; set; }
}
class Bad extends A {}
echo "Bad class exists\n";
