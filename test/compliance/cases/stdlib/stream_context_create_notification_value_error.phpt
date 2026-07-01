--TEST--
stream_context_create() flat notification option throws ValueError (#14482, ext/standard/streams.c)
--FILE--
<?php
try {
    stream_context_create(['notification' => static function (): void {}]);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
Options should have the form ["wrappername"]["optionname"] = $value
--CREDITS--
PurHur/php-compiler issue #14482
