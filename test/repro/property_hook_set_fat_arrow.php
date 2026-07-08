<?php
class Box {
    private string $stored = '';
    public string $x {
        get => $this->stored;
        set($v) => $this->stored = $v;
    }
}
$box = new Box();
$box->x = 'hi';
echo $box->x, "\n";
