<?php
$s = "a\\nb\\tc\\\\d";
var_dump(stripcslashes($s));
var_dump(addcslashes("foo[]", 'A..z'));
