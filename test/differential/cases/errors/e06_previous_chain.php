<?php
// @differential-skip-aot: AOT UncaughtThrowPrinter does not walk Exception::$previous (#36383)
try {
    throw new Exception('inner');
} catch (Exception $e) {
    throw new Exception('outer', 0, $e);
}
