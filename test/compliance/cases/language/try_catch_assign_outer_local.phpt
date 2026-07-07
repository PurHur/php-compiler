--TEST--
Language: try/catch assigns outer local visible after merge (#17158, Zend/zend_exceptions.c)
--FILE--
<?php
$error = '';
try {
    throw new Exception();
} catch (Exception $e) {
    $error = 'caught';
}
echo "error=$error\n";
--EXPECT--
error=caught
