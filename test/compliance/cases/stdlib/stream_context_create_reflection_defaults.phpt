--TEST--
stdlib stream_context_create Reflection ?array options/params = null (#25069)
--FILE--
<?php
$r = new ReflectionFunction('stream_context_create');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '-',
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A',
        "\n";
}
$ctx = stream_context_create();
echo 'omit_ok=', (int) (is_resource($ctx) || is_array($ctx) || is_object($ctx)), "\n";
$ctxNull = stream_context_create(options: null, params: null);
echo 'named_null_ok=', (int) (is_resource($ctxNull) || is_array($ctxNull) || is_object($ctxNull)), "\n";
?>
--EXPECT--
options type=?array def=NULL
params type=?array def=NULL
omit_ok=1
named_null_ok=1
