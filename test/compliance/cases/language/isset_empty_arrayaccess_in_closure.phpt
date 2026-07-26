--TEST--
Language: isset()/empty() on ArrayAccess inside closure returns bool (#23450)
--FILE--
<?php
class H implements ArrayAccess {
    private $d = ["a" => null, "b" => 1];
    public function offsetExists($k): bool { return array_key_exists($k, $this->d); }
    public function offsetGet($k): mixed { return $this->d[$k]; }
    public function offsetSet($k, $v): void { $this->d[$k] = $v; }
    public function offsetUnset($k): void { unset($this->d[$k]); }
}

$o = new H;
echo "tl_isset="; var_export(isset($o["a"])); echo "\n";
echo "tl_empty="; var_export(empty($o["a"])); echo "\n";

$fnIsset = function () {
    $o = new H;
    return isset($o["a"]);
};
$fnEmpty = function () {
    $o = new H;
    return empty($o["a"]);
};
$fnMissing = function () {
    $o = new H;
    return isset($o["missing"]);
};
$fnAnon = function () {
    $o = new class implements ArrayAccess {
        private $d = ["a" => null];
        public function offsetExists($k): bool { return array_key_exists($k, $this->d); }
        public function offsetGet($k): mixed { return $this->d[$k]; }
        public function offsetSet($k, $v): void { $this->d[$k] = $v; }
        public function offsetUnset($k): void { unset($this->d[$k]); }
    };
    return isset($o["a"]);
};

echo "cl_isset="; var_export($fnIsset()); echo "\n";
echo "cl_empty="; var_export($fnEmpty()); echo "\n";
echo "cl_missing="; var_export($fnMissing()); echo "\n";
echo "cl_anon="; var_export($fnAnon()); echo "\n";
--EXPECT--
tl_isset=true
tl_empty=true
cl_isset=true
cl_empty=true
cl_missing=false
cl_anon=true
