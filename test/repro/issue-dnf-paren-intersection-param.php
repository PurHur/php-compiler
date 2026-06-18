<?php
declare(strict_types=1);

interface I1 {}
interface I2 {}
class Both implements I1, I2 {}

function accepts((I1&I2) $o): string { return 'ok'; }
function returns(): (I1&I2) { return new Both(); }

var_export(accepts(new Both()));
echo "\n";
var_export(returns() instanceof Both);
echo "\n";
