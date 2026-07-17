<?php
class User {
    public string $name {
        set(string $value) { $this->name = ucfirst($value); }
        get => $this->name;
    }
}
$u = new User();
$u->name = "alice";
echo $u->name . "\n";
