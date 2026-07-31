--TEST--
language: exception from __get is catchable (issue #25911, Zend/zend_object_handlers.c)
--FILE--
<?php
class MagicGetThrow {
    public function __get(string $name) {
        throw new RuntimeException("get $name");
    }
}
try {
    echo (new MagicGetThrow)->missing;
} catch (Throwable $e) {
    echo "caught=", get_class($e), ":", $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
caught=RuntimeException:get missing
after
