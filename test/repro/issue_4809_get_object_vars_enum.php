<?php
enum Color: string { case Red = 'red'; }
enum Unit { case A; }
var_export(get_object_vars(Color::Red));
echo "\n";
var_export(get_object_vars(Unit::A));
echo "\n";
