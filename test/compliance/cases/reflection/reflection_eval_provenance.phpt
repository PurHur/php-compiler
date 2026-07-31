--TEST--
Reflection: eval()'d class/method/function getFileName + start/end lines (#26032)
--FILE--
<?php
// Single-quoted eval bodies: JitMcjitEmbed must not see interpolatable $pad inside "..." (#4964).
eval('class E { public function f() {} }');
$r = new ReflectionClass('E');
echo 'file=', $r->getFileName(), "\n";
echo 'start=', $r->getStartLine(), ' end=', $r->getEndLine(), "\n";
$m = new ReflectionMethod('E', 'f');
echo 'mfile=', $m->getFileName(), "\n";
echo 'mstart=', $m->getStartLine(), "\n";
eval('function ef() { return 1; }');
$rf = new ReflectionFunction('ef');
echo 'ffile=', $rf->getFileName(), "\n";
echo 'fstart=', $rf->getStartLine(), "\n";
--EXPECTF--
file=%s(%d) : eval()'d code
start=1 end=1
mfile=%s(%d) : eval()'d code
mstart=1
ffile=%s(%d) : eval()'d code
fstart=1
