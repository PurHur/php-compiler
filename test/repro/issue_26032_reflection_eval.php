<?php
// Repro for #26032 — Zend vs VM Reflection provenance after eval().
eval('class E { public function f() {} }');
$r = new ReflectionClass('E');
echo 'file=', json_encode($r->getFileName()), "\n";
echo 'start=', $r->getStartLine(), ' end=', $r->getEndLine(), "\n";
$m = new ReflectionMethod('E', 'f');
echo 'mfile=', json_encode($m->getFileName()), "\n";
echo 'mstart=', $m->getStartLine(), "\n";
eval('function ef() { return 1; }');
$rf = new ReflectionFunction('ef');
echo 'ffile=', json_encode($rf->getFileName()), "\n";
echo 'fstart=', $rf->getStartLine(), "\n";
