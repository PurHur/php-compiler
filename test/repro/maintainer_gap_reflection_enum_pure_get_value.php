<?php

enum PureEnum {
    case Alpha;
}

echo (new ReflectionEnum(PureEnum::class))->getCase('Alpha')->getValue()->name, "\n";
