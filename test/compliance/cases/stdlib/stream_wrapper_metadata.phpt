--TEST--
stdlib stream wrapper stream_metadata — touch()/chmod() dispatch (#8689, php-src filestat.c)
--FILE--
<?php
$GLOBALS['meta_touch'] = false;
$GLOBALS['meta_chmod'] = false;
class MetaWrap {
    public function stream_metadata(string $path, int $option, mixed $value): bool {
        if (STREAM_META_TOUCH === $option) {
            $GLOBALS['meta_touch'] = true;
        }
        if (STREAM_META_ACCESS === $option) {
            $GLOBALS['meta_chmod'] = true;
        }
        return true;
    }
}
stream_wrapper_register('metawrap', MetaWrap::class);
var_export(touch('metawrap://x'));
echo "\n";
var_export(chmod('metawrap://x', 0644));
echo "\n";
var_export($GLOBALS['meta_touch']);
echo "\n";
var_export($GLOBALS['meta_chmod']);
echo "\n";
--EXPECT--
true
true
true
true
