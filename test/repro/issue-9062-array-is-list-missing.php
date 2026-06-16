<?php

var_dump(array_is_list([]));
var_dump(array_is_list(["a", "b"]));
var_dump(array_is_list([1 => "x"]));
var_dump(array_is_list([0 => "x", 2 => "y"]));
var_dump(array_is_list(["0" => "x", 1 => "y"]));

