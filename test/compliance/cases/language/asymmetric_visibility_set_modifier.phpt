--TEST--
PHP 8.4 asymmetric visibility: brace hook private set modifier (#7148, zend_language_parser.y)
--FILE--
<?php
class User {
    public string $email { get; private set; }

    public function __construct(string $email) {
        $this->email = $email;
    }
}
$u = new User('a@b.c');
echo $u->email, "\n";
try {
    $u->email = 'x';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
a@b.c
Error: Cannot modify private(set) property User::$email from global scope
