--TEST--
Language: __unserialize/__set_state array param smoke (#26501)
--FILE--
<?php
class Good {
    public $x = 0;
    public function __serialize(): array { return ['x' => $this->x]; }
    public function __unserialize(array $data): void { $this->x = $data['x'] ?? 0; }
    public static function __set_state(array $a): object {
        $o = new self;
        $o->x = $a['x'] ?? 0;
        return $o;
    }
}
$g = new Good();
$g->x = 7;
$u = unserialize(serialize($g));
echo $u->x, "\n";
$s = Good::__set_state(['x' => 9]);
echo $s->x, "\n";
--EXPECT--
7
9
