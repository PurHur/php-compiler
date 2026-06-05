<?php
enum E: string { case A = 'x'; }
var_export(property_exists(E::A, 'name'));
echo "\n";
var_export(property_exists(E::A, 'value'));
echo "\n";
var_export(property_exists(E::A, 'missing'));
echo "\n";
