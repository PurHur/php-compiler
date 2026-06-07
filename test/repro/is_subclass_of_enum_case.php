<?php
enum E: int { case A = 1; }
enum U { case X; }
var_export(is_subclass_of(E::A, 'BackedEnum'));
var_export(is_subclass_of(E::A, 'UnitEnum'));
var_export(is_subclass_of(U::X, 'UnitEnum'));
var_export(is_subclass_of(U::X, 'BackedEnum'));
var_export(is_subclass_of(E::A, 'E'));
