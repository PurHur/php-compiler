<?php
// #23511 — array_column() must not read private props via storage/__get
class R {
    private $name;
    public function __construct(string $n) { $this->name = $n; }
    public function __get(string $k): string { return $this->name; }
}
echo json_encode(array_column([new R('x'), new R('y')], 'name')), "\n";
