<?php
class P {}
trait T {
    public function m() {}
}
class C extends P {
    use T { T::m insteadof P; }
}
echo "unreached\n";
