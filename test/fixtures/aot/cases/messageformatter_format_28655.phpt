--TEST--
AOT MessageFormatter::format named placeholder (#28655)
--FILE--
<?php
echo (new MessageFormatter('en_US', 'Hello {name}'))->format(['name' => 'World']), "\n";
--EXPECT--
Hello World
