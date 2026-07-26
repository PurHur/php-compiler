--TEST--
stdlib array_column() skips private/protected object props (no __get) (#23511)
--FILE--
<?php
class R {
    private $name;
    public function __construct(string $n) { $this->name = $n; }
    public function __get(string $k): string { return $this->name; }
}
echo json_encode(array_column([new R('x'), new R('y')], 'name')), "\n";
class P {
    public function __construct(public string $name) {}
}
echo json_encode(array_column([new P('a'), new P('b')], 'name')), "\n";
class Prot {
    protected $name;
    public function __construct(string $n) { $this->name = $n; }
    public function __get(string $k): string { return $this->name; }
}
echo json_encode(array_column([new Prot('z')], 'name')), "\n";
class Dyn {
    public function __get(string $k) { return 'magic'; }
}
echo json_encode(array_column([new Dyn()], 'name')), "\n";
?>
--EXPECT--
[]
["a","b"]
[]
[]
