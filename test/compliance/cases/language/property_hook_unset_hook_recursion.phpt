--TEST--
unset() hook body unset($this->prop) must not re-enter unset hook (issue #9625, zend_property_hooks.c)
--FILE--
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
--EXPECT--
ok
