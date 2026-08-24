<?php
// #34403 — bare `: object` must accept stdClass (and any object).
function f(): object
{
    return new stdClass;
}
$o = f();
$o->x = 1;
echo $o->x, "\n";

class User
{
}
function h(): object
{
    return new User();
}
echo get_class(h()), "\n";

function bad(): object
{
    return 1;
}
try {
    bad();
    echo "no\n";
} catch (TypeError $e) {
    echo "te\n";
}
