<?php

declare(strict_types=1);

class Secret
{
    public int $secret = 42;
}

$blob = serialize(new Secret());
$obj = unserialize($blob, ['allowed_classes' => false]);
var_dump($obj);
var_dump($obj instanceof __PHP_Incomplete_Class);
var_dump($obj->secret);
