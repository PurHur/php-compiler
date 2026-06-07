<?php
declare(strict_types=1);
var_dump(function_exists('class_meth_exists'));

class Box {
    public function __construct() {}
}
var_dump(class_meth_exists('Box', '__construct'));
