<?php

/**
 * Repro #26409 — (bool) on object with empty __toString must be true
 * (zend_std_cast_object_to_type / convert_to_boolean; not string truthiness).
 */
class E
{
    public function __toString()
    {
        return '';
    }
}

class N
{
    public function __toString()
    {
        return 'x';
    }
}

var_export((bool) (new E));
echo "\n";
var_export((bool) (new N));
echo "\n";
var_export((bool) (new stdClass));
echo "\n";
var_export(boolval(new E));
echo "\n";
