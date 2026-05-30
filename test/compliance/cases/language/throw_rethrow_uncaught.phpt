--TEST--
Language: bare throw; escapes to uncaught handler (#3508)
--FILE--
<?php
class Ex {
    public string $message = 'orig';
}

try {
    throw new Ex();
} catch (Ex $e) {
    throw;
}
--EXPECT_EXIT--
255
