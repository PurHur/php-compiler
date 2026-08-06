<?php
/** Issue #26909 — AOT parse_ini_string runtime subject matches Zend/VM/JIT. */
$r = parse_ini_string('a=1' . PHP_EOL . 'b=hello');
echo $r['a'], ',', $r['b'], "\n";
