--TEST--
AOT: typed array property with non-empty literal default (#24086)
--FILE--
<?php
class C {
    private array $c = [1, 2];
    public array $pub = [7, 8];
    public array $assoc = ['a' => 1, 'b' => 2];
    public array $empty = [];

    public function countPrivate(): int
    {
        return count($this->c);
    }

    public function elemPrivate(): int
    {
        return $this->c[1];
    }

    public function viaLocal(): int
    {
        $x = $this->c;

        return count($x);
    }
}

$o = new C;
echo $o->countPrivate(), "\n";
echo $o->elemPrivate(), "\n";
echo $o->viaLocal(), "\n";
echo count($o->pub), "\n";
echo $o->pub[1], "\n";
echo $o->assoc['b'], "\n";
echo count($o->empty), "\n";
--EXPECT--
2
2
2
2
8
2
0
--EXPECT_EXIT--
0
