<?php
// Ordinary PHP: trait use with a promoted-free constructor. Passes both backends.
trait Greets { public function hi(): string { return "hi " . $this->name; } }
class P { public string $name; public function __construct(string $n) { $this->name = $n; } }
class Q extends P { use Greets; }
echo (new Q("bob"))->hi(), "\n";
