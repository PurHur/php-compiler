--TEST--
List/array destructuring on property hooks invokes set hook (issue #6434, zend_property_hooks.c)
--FILE--
<?php
class Box {
    private string $_v = '';
    public string $label {
        get => $this->_v;
        set => $this->_v = '[' . $value . ']';
    }
}
$c = new Box();
[$c->label] = ['hi'];
echo "int: ", $c->label, "\n";

class Mixed {
    private string $_h = '';
    public string $hooked {
        get => $this->_h;
        set => $this->_h = 'H:' . $value;
    }
    public string $plain = '';
}
$m = new Mixed();
[$m->hooked, $m->plain] = ['a', 'b'];
echo "mixed: ", $m->hooked, " ", $m->plain, "\n";

class Keyed {
    private string $_v = '';
    public string $x {
        get => $this->_v;
        set => $this->_v = 'K:' . $value;
    }
}
$k = new Keyed();
['a' => $k->x] = ['a' => 'val'];
echo "keyed: ", $k->x, "\n";
--EXPECT--
int: [hi]
mixed: H:a b
keyed: K:val
