<?php
trait T { public function f(): void { echo "t\n"; } }
class C {
    use T { f as protected other; }
    #[\Override]
    public function f(): void { echo "c\n"; }
}
(new C)->f();
