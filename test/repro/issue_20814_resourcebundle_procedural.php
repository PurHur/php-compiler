<?php
// Repro #20814 — resourcebundle_create/get/locales procedural aliases (php-src-strict).
echo 'oop=', (ResourceBundle::create('en', 'ICUDATA') instanceof ResourceBundle ? 'yes' : 'no'), "\n";
foreach (['resourcebundle_create', 'resourcebundle_get', 'resourcebundle_locales', 'resourcebundle_count'] as $f) {
    echo $f, '=', (function_exists($f) ? 'yes' : 'no'), "\n";
}
$rb = resourcebundle_create('en', null);
echo 'create_ok=', ($rb instanceof ResourceBundle ? 'yes' : 'no'), "\n";
$ver = resourcebundle_get($rb, 'Version');
echo 'get_ok=', (is_string($ver) && $ver !== '' ? 'yes' : 'no'), "\n";
$locales = resourcebundle_locales('ICUDATA');
echo 'locales_ok=', (is_array($locales) && count($locales) > 0 ? 'yes' : 'no'), "\n";
