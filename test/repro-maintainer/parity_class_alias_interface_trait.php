<?php

declare(strict_types=1);

interface I
{
}

var_dump(class_alias('I', 'I2'));
var_dump(interface_exists('I2'));

trait T
{
}

var_dump(class_alias('T', 'T2'));
var_dump(trait_exists('T2'));

class C implements I
{
}

$obj = new C();
var_dump($obj instanceof I2);
