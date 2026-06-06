<?php
class User {
    public string $email { get; private set; }
    public function __construct(string $email) { $this->email = $email; }
}
$u = new User('a@b.c');
try { $u->email = 'x'; } catch (Error $e) { echo get_class($e), "\n"; }
