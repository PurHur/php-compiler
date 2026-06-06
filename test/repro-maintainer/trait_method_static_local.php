<?php
trait Counter {
    public function f(): void {
        static $n = 0;
        $n++;
        echo $n, "\n";
    }
}
class C1 { use Counter; }
class C2 { use Counter; }

(new C1())->f();
(new C1())->f();
(new C2())->f();
