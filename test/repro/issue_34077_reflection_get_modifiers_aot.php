<?php

abstract class A {}
final class F {}
class Plain {}
interface I {}
trait T {}

echo 'A=', (new ReflectionClass(A::class))->getModifiers(), "\n";
echo 'F=', (new ReflectionClass(F::class))->getModifiers(), "\n";
echo 'Plain=', (new ReflectionClass(Plain::class))->getModifiers(), "\n";
echo 'I=', (new ReflectionClass(I::class))->getModifiers(), "\n";
echo 'T=', (new ReflectionClass(T::class))->getModifiers(), "\n";
echo 'Exception=', (new ReflectionClass(Exception::class))->getModifiers(), "\n";
echo 'Closure=', (new ReflectionClass(Closure::class))->getModifiers(), "\n";
