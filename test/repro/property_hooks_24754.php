<?php
class User {
    public string $name {
        set(string $value) {
            $this->name = ucfirst($value);
        }
        get => $this->name;
    }
    public function __construct(string $name) {
        $this->name = $name;
    }
}
$u = new User("alice");
echo $u->name . "\n";
