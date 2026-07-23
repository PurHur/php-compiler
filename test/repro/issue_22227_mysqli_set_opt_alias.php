<?php
/** Repro #22227 — mysqli_set_opt alias of mysqli_options. */
foreach (['mysqli_options', 'mysqli_set_opt'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
