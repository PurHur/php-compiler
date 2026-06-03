<?php
interface I {}
interface J extends I {}
interface K extends J {}
var_export(class_parents('I'));
echo "\n";
var_export(class_parents('J'));
echo "\n";
var_export(class_parents('K'));
echo "\n";
