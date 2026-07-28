--TEST--
Language: typed array property with non-empty literal default (#24086)
--FILE--
<?php
class C {
    private array $c = [1, 2];
    public array $pub = [7, 8];

    public function countPrivate(): int
    {
        return count($this->c);
    }

    public function elemPrivate(): int
    {
        return $this->c[1];
    }
}

$o = new C;
echo $o->countPrivate(), "\n";
echo $o->elemPrivate(), "\n";
echo count($o->pub), "\n";
echo $o->pub[1], "\n";
--EXPECT--
2
2
2
8
