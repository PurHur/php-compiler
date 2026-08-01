<?php
/** Repro #22898 — ICUDATA-region key set / Countries count vs Zend. */
$r = ResourceBundle::create('en', 'ICUDATA-region');
echo 'count=', count($r), "\n";
foreach ($r as $k => $_) {
    echo $k, "\n";
}
$c = $r->get('Countries');
echo 'countries=', count($c), "\n";
$chagos = @$r->get('Countries%chagos');
echo 'chagos=', (null === $chagos || false === $chagos) ? 'null' : 'obj', "\n";
