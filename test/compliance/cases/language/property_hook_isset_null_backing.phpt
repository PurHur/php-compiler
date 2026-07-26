--TEST--
Language: isset()/empty() on hooked prop with null distinct backing invokes get (#23339, re-#17260, zend_property_hooks.c)
--FILE--
<?php
class C {
    private ?string $_n = null;
    public string $name {
        get => $this->_n ?? 'anon';
        set(?string $v) => $this->_n = $v;
    }
}
$c = new C();
echo 'isset=', isset($c->name) ? '1' : '0', "\n";
echo 'empty=', empty($c->name) ? '1' : '0', "\n";
$c->name = null;
echo 'afternull isset=', isset($c->name) ? '1' : '0', ' empty=', empty($c->name) ? '1' : '0', "\n";
unset($c->name);
echo 'afterunset isset=', isset($c->name) ? '1' : '0', ' empty=', empty($c->name) ? '1' : '0', ' get=', $c->name, "\n";
--EXPECT--
isset=1
empty=0
afternull isset=1 empty=0
afterunset isset=0 empty=1 get=anon
