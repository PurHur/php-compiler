<?php

class C
{
}

$ed = (new ReflectionClass(DateTime::class))->getExtension();
if (null === $ed) {
    echo "date-null\n";
} else {
    echo get_class($ed), ':', $ed->getName(), "\n";
}

$eu = (new ReflectionClass(C::class))->getExtension();
echo null === $eu ? "user-null\n" : "user-bad\n";

$er = (new ReflectionClass(ReflectionClass::class))->getExtension();
if (null === $er) {
    echo "refl-null\n";
} else {
    echo get_class($er), ':', $er->getName(), "\n";
}
