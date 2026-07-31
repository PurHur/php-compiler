--TEST--
stdlib libxml_use_internal_errors/libxml_get_errors Reflection stubs (#25844)
--FILE--
<?php
foreach (['libxml_use_internal_errors', 'libxml_get_errors'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '-';
        $extra = '';
        if ($p->isDefaultValueAvailable()) {
            $extra = '='.var_export($p->getDefaultValue(), true);
        }
        $ps[] = $p->getName().':'.$t.$extra;
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : '-';
    echo $f, ' => ', $ret, ' :: ', implode(', ', $ps), "\n";
}
?>
--EXPECT--
libxml_use_internal_errors => bool :: use_errors:?bool=NULL
libxml_get_errors => array :: 
