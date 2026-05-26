/*
 * soundex() runtime for VM/JIT/AOT (issue #2416).
 * PHP-compatible Soundex (ASCII letters); returns 4-char code via __string__init.
 */

#include <stddef.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

/* PHP 8 soundex_table[26] — 0 = vowel/H/W (skip), else ASCII digit char. */
static const unsigned char soundex_table[26] = {
    0, '1', '2', '3', 0, '1', '2', 0, 0, '2', '2', '4', '5', '5', 0, '1', '2', '6', '2', '3', 0, '1', 0, '2', 0, '2'
};

static char phpc_soundex_upper(unsigned char c)
{
    if (c >= 'a' && c <= 'z') {
        return (char) (c - 32);
    }

    return (char) c;
}

static unsigned char phpc_soundex_digit(char upper)
{
    if (upper < 'A' || upper > 'Z') {
        return 0;
    }

    return soundex_table[(unsigned char) upper - 'A'];
}

__string__ *phpc_soundex(const char *str)
{
    char code[5] = {'0', '0', '0', '0', '\0'};
    unsigned char last = 0;
    size_t pos = 0;
    int started = 0;

    if (NULL == str) {
        str = "";
    }

    for (size_t i = 0; str[i] != '\0'; ++i) {
        char upper = phpc_soundex_upper((unsigned char) str[i]);
        if (upper < 'A' || upper > 'Z') {
            continue;
        }
        unsigned char digit = phpc_soundex_digit(upper);
        if (!started) {
            code[0] = upper;
            pos = 1;
            last = digit;
            started = 1;
            continue;
        }
        if (digit != last) {
            if (0 != digit && pos < 4) {
                code[pos++] = (char) digit;
            }
            last = digit;
        }
    }

    return __string__init(4, code);
}
