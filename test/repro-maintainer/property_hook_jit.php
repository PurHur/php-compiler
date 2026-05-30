<?php
class User {
    public string $email {
        set (string $value) {
            if (!str_contains($value, '@')) {
                echo "reject\n";
                return;
            }
            $this->email = $value;
        }
    }
}
$u = new User();
$u->email = 'bad';
$u->email = 'a@b.c';
echo $u->email, "\n";
