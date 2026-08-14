<?php
/**
 * mb_strlen / mb_convert_encoding / iconv_* excess argc → at most (#30891).
 * php-src: ext/mbstring/mbstring.c, ext/iconv/iconv.c
 */
function t(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}

t('mb_strlen', static fn () => mb_strlen('a', 'UTF-8', 1));
t('mb_convert_encoding', static fn () => mb_convert_encoding('a', 'UTF-8', 'UTF-8', 1));
t('iconv_strlen', static fn () => iconv_strlen('a', 'UTF-8', 1));
t('iconv_substr', static fn () => iconv_substr('abcd', 1, 2, 'UTF-8', 1));
t('iconv_strpos', static fn () => iconv_strpos('abcd', 'b', 0, 'UTF-8', 1));
t('mb_strlen_lo', static fn () => mb_strlen());
t('mb_convert_encoding_lo', static fn () => mb_convert_encoding('a'));
t('iconv_strlen_lo', static fn () => iconv_strlen());
t('iconv_substr_lo', static fn () => iconv_substr('abcd'));
echo 'ok_strlen:', (string) mb_strlen('ab'), "\n";
echo 'ok_iconv:', (string) iconv_strlen('ab', 'UTF-8'), "\n";
echo 'ok_conv:', mb_convert_encoding('a', 'UTF-8', 'UTF-8'), "\n";
