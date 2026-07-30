--TEST--
stdlib Reflection: highlight_string/file string|bool; substr_count length ?int=null; preg_quote delimiter ?string=null (#25472)
--FILE--
<?php
foreach (['highlight_string', 'highlight_file'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}

$r = new ReflectionFunction('substr_count');
$p = $r->getParameters()[3];
echo 'substr_count length type=', $p->hasType() ? (string) $p->getType() : 'NONE',
    ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a',
    "\n";
echo 'substr_count=', substr_count('aaa', 'a'), "\n";

$r = new ReflectionFunction('preg_quote');
$p = $r->getParameters()[1];
echo 'preg_quote delimiter type=', $p->hasType() ? (string) $p->getType() : 'NONE',
    ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a',
    "\n";
echo 'preg_quote=', preg_quote('a.b'), "\n";
?>
--EXPECT--
highlight_string ret=string|bool
highlight_file ret=string|bool
substr_count length type=?int def=NULL
substr_count=3
preg_quote delimiter type=?string def=NULL
preg_quote=a\.b
