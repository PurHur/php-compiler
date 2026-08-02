<?php
/** Repro #26802 — AOT iterator_to_array(Generator) dominate-uses / values. */
function gen() {
    yield "a" => 1;
    yield "b" => 2;
}
$a = iterator_to_array(gen());
$b = iterator_to_array(gen(), false);
echo $a["a"], ",", $a["b"], "\n";
echo $b[0], ",", $b[1], "\n";
