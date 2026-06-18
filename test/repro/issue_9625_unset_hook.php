<?php
class C {
    public string $name {
        get => $this->name;
        unset { unset($this->name); }
    }
    private string $name = 'a';
}
$c = new C;
unset($c->name);
echo "ok\n";
echo 'property_exists=' . var_export(property_exists($c, 'name'), true) . "\n";
echo 'isset=' . var_export(isset($c->name), true) . "\n";
