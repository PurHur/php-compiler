--TEST--
get_required_files/get_mangled_object_vars Reflection return array (VM, issue #27785, basic_functions.stub.php)
--FILE--
<?php
foreach (['get_required_files', 'get_mangled_object_vars', 'get_included_files'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}
$req = get_required_files();
$mangled = get_mangled_object_vars(new stdClass());
echo 'required_ok=', is_array($req) ? '1' : '0', "\n";
echo 'mangled_ok=', is_array($mangled) ? '1' : '0', "\n";
?>
--EXPECT--
get_required_files=array
get_mangled_object_vars=array
get_included_files=array
required_ok=1
mangled_ok=1
