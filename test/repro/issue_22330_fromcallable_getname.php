<?php
// Repro #22330 — Closure::fromCallable ReflectionFunction::getName() vs Zend.
$f = Closure::fromCallable('strlen');
echo (new ReflectionFunction($f))->getName(), "\n";
$g = Closure::fromCallable(['DateTime', 'createFromFormat']);
echo (new ReflectionFunction($g))->getName(), "\n";
