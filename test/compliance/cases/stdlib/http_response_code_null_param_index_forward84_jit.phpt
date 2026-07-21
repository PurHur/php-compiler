--TEST--
stdlib http_response_code(null) deprecation cites parameter #1 JIT (#21705, ext/standard/http_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
http_response_code_null_param_index_forward84.php
--EXPECT--
http_response_code(): Passing null to parameter #1 ($response_code) of type int is deprecated
false
