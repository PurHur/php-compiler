--TEST--
stdlib preg_match() null pattern — TypeError not empty-regex warning (#17269, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);

try {
    preg_match(null, 'subject');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
preg_match(): Argument #1 ($pattern) must be of type string, null given
