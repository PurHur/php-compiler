<?php
interface I1 {}
interface I2 {}
class Both implements I1, I2 {}

function returns(): (I1&I2) { return new Both(); }

var_export(returns() instanceof Both);
echo "\n";
