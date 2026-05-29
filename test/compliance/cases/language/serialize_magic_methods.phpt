--TEST--
language __serialize / __unserialize magic methods (VM, issue #3368)
--FILE--
<?php
class Point {
    public int $x = 1;

    public function __serialize(): array
    {
        return ['x' => $this->x];
    }

    public function __unserialize(array $data): void
    {
        $this->x = $data['x'] + 1;
    }
}

$p = new Point();
$s = serialize($p);
$q = unserialize($s);
echo $q->x, "\n";

$denied = unserialize($s, ['allowed_classes' => false]);
echo $denied === false ? "denied\n" : "allowed\n";

$listed = unserialize($s, ['allowed_classes' => ['Point']]);
echo $listed->x, "\n";
--EXPECT--
2
denied
2
