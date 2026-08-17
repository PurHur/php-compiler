<?php
/**
 * #27785 — get_required_files / get_mangled_object_vars Reflection return array
 * (ext/standard/basic_functions.stub.php; get_included_files sibling already green).
 */
foreach (['get_required_files', 'get_mangled_object_vars', 'get_included_files'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
$req = get_required_files();
$mangled = get_mangled_object_vars(new stdClass());
echo 'required_ok=', is_array($req) ? '1' : '0', "\n";
echo 'mangled_ok=', is_array($mangled) ? '1' : '0', "\n";
