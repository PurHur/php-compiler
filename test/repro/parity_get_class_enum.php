<?php
enum E {
    case A;
    case B;
}

enum Status: string {
    case OK = 'ok';
}

var_dump(get_class(E::A));
var_dump(get_class(E::B));
var_dump(get_class(Status::OK));

class Plain
{
}

var_dump(get_class(new Plain()));
