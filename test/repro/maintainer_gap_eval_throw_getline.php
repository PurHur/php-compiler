<?php
// eval() throw: Exception::getLine() is 1-based in the eval string (Zend).
// __LINE__ already matches after #25809; getLine() is still off-by-one (wrapEvalCode prepends <?php\n).
error_reporting(E_ALL);

echo 'line_const=', eval('return __LINE__;'), "\n";

try {
    eval('throw new Exception("x");');
} catch (Exception $e) {
    echo 'throw_line=', $e->getLine(), "\n";
    echo 'throw_file=', $e->getFile(), "\n";
}
