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
echo $c->label, "\n";
