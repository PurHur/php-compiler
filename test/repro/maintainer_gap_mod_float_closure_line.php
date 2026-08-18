<?php

error_reporting(E_ALL);
$fn = function () {
    echo 5.5 % 2, "\n"; // Zend: this line; VM currently cites $fn() below
};
$fn();
