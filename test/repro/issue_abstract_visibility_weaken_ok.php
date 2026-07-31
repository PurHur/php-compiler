<?php
abstract class A { abstract protected function f(): void; }
class B extends A { public function f(): void {} }
echo "LOADED\n";
