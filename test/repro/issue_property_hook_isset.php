<?php
class C {
    public ?string $x {
        get { throw new Exception('get must not run for isset'); }
    }
}
$c = new C();
var_dump(isset($c->x));
echo "ok\n";
