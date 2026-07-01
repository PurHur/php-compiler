--TEST--
stdlib session_cache_expire() default and setter (issue #14613, ext/session/session.c)
--FILE--
<?php
echo function_exists('session_cache_expire') ? 'yes' : 'no', "\n";
echo session_cache_expire(), "\n";
session_cache_expire(240);
echo session_cache_expire(), "\n";
try {
    session_cache_expire(0);
} catch (ValueError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
yes
180
240
ValueError
