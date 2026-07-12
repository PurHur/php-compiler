<?php
class C {
    private string $x = 'a';
    public string $x {
        get => $this->x;
        unset { unset($this->x); }
    }
}
$c = new C;
unset($c->x);
echo "PASS_PROPERTY_HOOK_UNSET\n";
echo 'isset=' . var_export(isset($c->x), true) . "\n";
