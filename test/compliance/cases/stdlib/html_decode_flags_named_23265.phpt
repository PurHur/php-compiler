--TEST--
stdlib htmlspecialchars_decode/html_entity_decode Reflection flags + named args (#23265)
--FILE--
<?php
foreach (['htmlspecialchars_decode', 'html_entity_decode'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' required=', $r->getNumberOfRequiredParameters(),
        ' argc=', $r->getNumberOfParameters(),
        ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-',
        "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName();
        if ($p->hasType()) {
            echo ':', $p->getType();
        }
        echo $p->isOptional() ? ' OPT' : ' REQ';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            echo '=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
echo 'hsd=', htmlspecialchars_decode(string: '&amp;', flags: ENT_QUOTES), "\n";
echo 'hed=', html_entity_decode(string: '&amp;', flags: ENT_QUOTES, encoding: null), "\n";
try {
    htmlspecialchars_decode(string: '&amp;', quote_style: ENT_QUOTES);
    echo "quote_style_accepted\n";
} catch (Throwable $e) {
    echo 'quote_style=', $e->getMessage(), "\n";
}
?>
--EXPECT--
htmlspecialchars_decode required=1 argc=2 return=string
  string:string REQ
  flags:int OPT=11
html_entity_decode required=1 argc=3 return=string
  string:string REQ
  flags:int OPT=11
  encoding:?string OPT=NULL
hsd=&
hed=&
quote_style=Unknown named parameter $quote_style
