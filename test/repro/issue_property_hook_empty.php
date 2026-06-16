<?php
class C {
    public string $x {
        get { throw new Exception('get must not run for empty()'); }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$c = new C();
var_dump(empty($c->x));
echo "ok\n";
