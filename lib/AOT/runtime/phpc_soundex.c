/*
 * soundex() runtime for VM/JIT/AOT (issue #2416).
 * Byte-oriented American Soundex; ASCII letters only; no PHP internal wrappers.
 */

#include <ctype.h>
#include <stddef.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int phpc_soundex_code(unsigned char c)
{
    c = (unsigned char) tolower((int) c);
    switch (c) {
        case 'b':
        case 'f':
        case 'p':
        case 'v':
            return 1;
        case 'c':
        case 'g':
        case 'j':
        case 'k':
        case 'q':
        case 's':
        case 'x':
        case 'z':
            return 2;
        case 'd':
        case 't':
            return 3;
        case 'l':
            return 4;
        case 'm':
        case 'n':
            return 5;
        case 'r':
            return 6;
        default:
            return 0;
    }
}

static void phpc_soundex_fill(char *dest, const char *src, size_t srclen)
{
    size_t i;
    int j;
    int last;

    dest[0] = dest[1] = dest[2] = dest[3] = '0';
    dest[4] = '\0';

    if (0 == srclen) {
        return;
    }

    for (i = 0; i < srclen; ++i) {
        if (isalpha((unsigned char) src[i])) {
            break;
        }
    }
    if (i >= srclen) {
        return;
    }

    dest[0] = (char) toupper((unsigned char) src[i]);
    last = phpc_soundex_code((unsigned char) dest[0]);
    j = 1;

    for (++i; i < srclen && j < 4; ++i) {
        if (!isalpha((unsigned char) src[i])) {
            continue;
        }
        int code = phpc_soundex_code((unsigned char) src[i]);
        if (0 != code && code != last) {
            dest[j++] = (char) ('0' + code);
        }
        last = code;
    }
}

__string__ *phpc_soundex(__string__ *str)
{
    char result[5];
    const char *src;

    if (NULL == str) {
        src = "";
    } else {
        src = phpc_strdata(str);
    }

    phpc_soundex_fill(result, src, strlen(src));

    return __string__init(4, result);
}
