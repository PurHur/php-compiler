<?php
// After #36669 Analyzer marks instanceof as escaping → TYPE_VALUE subjects.
// emitInstanceOf must not load class_id from null __object__* (#36382).
// php-src: Zend/zend_operators.c instanceof_function / zend_is_instanceof
interface IFace
{
}
class Concrete implements IFace
{
}
$obj = new Concrete();
$other = new stdClass();
echo ($obj instanceof IFace) ? 'yes' : 'no', "\n";
echo ($other instanceof IFace) ? 'yes' : 'no', "\n";
$arr = [1];
echo ($arr instanceof Traversable) ? 'yes' : 'no', "\n";
