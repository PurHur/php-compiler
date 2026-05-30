--TEST--
AOT property set hook invokes lowered setter (issue #3723)
--FILE--
<?php
class User {
    public string $email {
        set (string $value) {
            echo "hook\n";
            $this->email = $value;
        }
    }
}
$u = new User();
$u->email = 'a@b.c';
echo "ok\n";
--EXPECT--
hook
ok
