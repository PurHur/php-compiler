<?php
class C {
    public ?string $x {
        get { throw new Exception('get must not run for empty'); }
    }
}
$c = new C();
var_dump(empty($c->x));
echo "ok\n";
