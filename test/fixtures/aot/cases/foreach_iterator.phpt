--TEST--
AOT foreach over Iterator object (#4011)
--FILE--
<?php
class T implements Iterator {
    private int $i = 0;
    public function current() { return $this->i; }
    public function key() { return $this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 3; }
}
foreach (new T() as $k => $v) {
    echo "$k:$v\n";
}
--EXPECT--
0:0
1:1
2:2
