<?php
class H {
    private int $v = 1;
    public int $x {
        get => $this->v;
        set (int $value) { $this->v = $value; }
    }
}
$h = new H();
try {
    $b =& $h->x;
    echo "getset_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $arr = [&$h->x];
    echo "arr_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

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

class V {
    private int $n = 0;
    public int $count {
        &get => $this->n;
        set(int $v) { $this->n = $v; }
    }
}
$v = new V;
$v->count = 3;
$r =& $v->count;
$r = 9;
echo $v->count, "\n";
