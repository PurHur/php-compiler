<?php
/**
 * #36382 — VALUE-boxed string local + if/elseif isset($s[$i]) must not clobber to [].
 *
 * php-src: Zend/zend_execute.c zend_isset_dim
 */
$path = '/' . 'hello';
if ('' !== $path && '/' !== $path[0]) {
    echo "prepend\n";
} elseif (isset($path[1]) && '/' === $path[1]) {
    echo "collapse\n";
} else {
    echo "keep\n";
}
var_dump($path);
