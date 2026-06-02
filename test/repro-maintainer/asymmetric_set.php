<?php
class User {
    public private(set) string $name = 'a';
}
$u = new User();
try {
    $u->name = 'b';
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
