<?php
class H {
    private int $v = 1;
    public int $x {
        get => $this->v;
        set (int $value) { $this->v = $value; }
    }
}
$h = new H();
$b =& $h->x;
$b = 5;
echo $h->x, "\n";

$arr = [&$h->x];
$arr[0] = 9;
echo $h->x, "\n";

class G {
    private int $v = 1;
    public int $x {
        get => $this->v;
    }
}
$g = new G();
try {
    $b =& $g->x;
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
