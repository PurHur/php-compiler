<?php
class Box {
    private int $stored = 0;
    public int $value {
        get => $this->stored;
        set => $this->stored = $value * 10;
    }
}
$box = new Box();
$box->value = 3;
echo $box->value, "\n";
