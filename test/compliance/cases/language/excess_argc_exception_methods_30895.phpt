--TEST--
language: Exception/Error get* excess argc → ArgumentCountError (#30895, zend_exceptions.c)
--FILE--
<?php
$e = new Exception('x', 0, new Exception('inner'));
foreach ([
  'getMessage' => fn() => $e->getMessage(1),
  'getCode' => fn() => $e->getCode(1),
  'getFile' => fn() => $e->getFile(1),
  'getLine' => fn() => $e->getLine(1),
  'getTrace' => fn() => $e->getTrace(1),
  'getTraceAsString' => fn() => $e->getTraceAsString(1),
  'getPrevious' => fn() => $e->getPrevious(1),
  'Error::getCode' => fn() => (new Error('e', 9))->getCode(1),
  'Error::getMessage' => fn() => (new TypeError('t'))->getMessage(1),
] as $label => $fn) {
  try {
    $fn();
    echo "$label ACCEPTED\n";
  } catch (Throwable $ex) {
    echo "$label ", get_class($ex), ': ', $ex->getMessage(), "\n";
  }
}
echo 'ok_msg=', $e->getMessage(), "\n";
echo 'ok_code=', (new Error('e', 9))->getCode(), "\n";
--EXPECT--
getMessage ArgumentCountError: Exception::getMessage() expects exactly 0 arguments, 1 given
getCode ArgumentCountError: Exception::getCode() expects exactly 0 arguments, 1 given
getFile ArgumentCountError: Exception::getFile() expects exactly 0 arguments, 1 given
getLine ArgumentCountError: Exception::getLine() expects exactly 0 arguments, 1 given
getTrace ArgumentCountError: Exception::getTrace() expects exactly 0 arguments, 1 given
getTraceAsString ArgumentCountError: Exception::getTraceAsString() expects exactly 0 arguments, 1 given
getPrevious ArgumentCountError: Exception::getPrevious() expects exactly 0 arguments, 1 given
Error::getCode ArgumentCountError: Error::getCode() expects exactly 0 arguments, 1 given
Error::getMessage ArgumentCountError: Error::getMessage() expects exactly 0 arguments, 1 given
ok_msg=x
ok_code=9
