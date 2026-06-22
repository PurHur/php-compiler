<?php
class User {
    public string $name {
        get => strtoupper($this->name);
        set => $value;
    }
}
$u = new User();
$u->name = 'alice';
echo json_encode($u), "\n";
