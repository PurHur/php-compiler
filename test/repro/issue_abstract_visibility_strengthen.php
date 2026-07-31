<?php
abstract class A { abstract protected function f(): void; }
class B extends A { private function f(): void {} }
echo "LOADED\n";
