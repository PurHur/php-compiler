<?php
/**
 * mb_str_split / mb_convert_case / mb_scrub / mb_substr_count excess argc → at most (#30786).
 * php-src: ext/mbstring/mbstring.c
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

t('mb_str_split', static fn () => mb_str_split('ab', 1, null, 1));
t('mb_convert_case', static fn () => mb_convert_case('a', MB_CASE_UPPER, 'UTF-8', 1));
t('mb_scrub', static fn () => mb_scrub('a', 'UTF-8', 1));
t('mb_substr_count', static fn () => mb_substr_count('aba', 'a', 'UTF-8', 1));
t('mb_str_split_lo', static fn () => mb_str_split());
echo 'ok_split:', implode(',', mb_str_split('ab', 1)), "\n";
echo 'ok_case:', mb_convert_case('a', MB_CASE_UPPER), "\n";
echo 'ok_count:', (string) mb_substr_count('aba', 'a'), "\n";
