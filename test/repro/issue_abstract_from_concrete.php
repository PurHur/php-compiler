<?php
class A { public function f(): void {} }
abstract class B extends A { abstract public function f(): void; }
echo "LOADED\n";
