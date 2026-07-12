<?php
class H {
    private int $v = 1;
    public int $x {
        get => $this->v;
        set (int $value) { $this->v = $value; }
    }
}
$h = new H();
$arr = [&$h->x];
$arr[0] = 9;
echo $h->x, "\n";
