<?php

class Base {}
class Child extends Base {}

$p = (new ReflectionClass(Child::class))->getParentClass();
if ($p === false) {
    echo "false\n";
} else {
    echo $p->getName(), "\n";
}

$p2 = (new ReflectionClass(Base::class))->getParentClass();
if ($p2 === false) {
    echo "false\n";
} else {
    echo $p2->getName(), "\n";
}

$p3 = (new ReflectionClass(Exception::class))->getParentClass();
if ($p3 === false) {
    echo "false\n";
} else {
    echo $p3->getName(), "\n";
}

$p4 = (new ReflectionClass(LogicException::class))->getParentClass();
if ($p4 === false) {
    echo "false\n";
} else {
    echo $p4->getName(), "\n";
}
