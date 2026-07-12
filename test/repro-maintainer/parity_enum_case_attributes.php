<?php
declare(strict_types=1);

enum E {
    #[\Deprecated(message: 'gone')]
    case A;
}

$case = (new ReflectionEnum(E::class))->getCase('A');
var_dump(count($case->getAttributes()));
echo $case->getAttributes()[0]->getName(), "\n";
