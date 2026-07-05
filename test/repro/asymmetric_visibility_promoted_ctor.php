<?php
class User {
    public function __construct(
        public (private(set)) string $name,
    ) {}
}
$u = new User('alice');
echo $u->name, "\n";
try {
    $u->name = 'bob';
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
