--TEST--
Language: ambiguous interface constants across require (#26672)
--RUNFILE--
interface_const_ambiguity_require/run.php
--EXPECTF--
PHP Fatal error:  Class X inherits both I::C and J::C, which is ambiguous in %s on line %d
--EXPECT_EXIT--
255
