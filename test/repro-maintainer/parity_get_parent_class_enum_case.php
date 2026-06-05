<?php

enum E: string
{
    case A = 'x';
}

var_export(get_parent_class(E::A));
