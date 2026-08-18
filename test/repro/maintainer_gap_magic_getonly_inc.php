<?php
error_reporting(E_ALL);
class GO {
    private $d = ['z' => 1];
    public function __get($k) {
        echo "__get($k)\n";
        return $this->d[$k];
    }
}
$g = new GO();
$g->z++;
echo "inc=", $g->z, "\n";
