<?php
class P {
    public readonly string $x;
    public function __construct(string $x) { $this->x = $x; }
}
class C extends P {
    public string $x;
}
echo "compiled\n";
