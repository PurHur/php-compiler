<?php
// Repro #26211 — json_validate Reflection types (ext/json/json.stub.php); needs PROFILE=8.4
$r = new ReflectionFunction('json_validate');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->getType() ? (string) $p->getType() : '(none)', "\n";
}
echo 'return=', $r->getReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
var_export(json_validate(json: '{}'));
echo "\n";
