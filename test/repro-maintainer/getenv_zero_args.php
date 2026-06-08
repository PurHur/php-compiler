<?php

$all = getenv();
var_dump(is_array($all), count($all) > 0);
var_dump(array_key_exists('PATH', $all) || array_key_exists('HOME', $all));
