/*
 * utf8_encode() / utf8_decode() runtime for AOT/JIT.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(utf8_encode/utf8_decode)
 */

#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static size_t phpc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

__string__ *__compiler_utf8_encode(__string__ *src)
{
    size_t src_len = phpc_strlen(src);
    const unsigned char *s = (const unsigned char *) phpc_strdata(src);
    size_t i;
    size_t out_cap = src_len * 2;
    unsigned char *buf;
    size_t out_len = 0;

    if (0 == src_len) {
        return __string__init(0, "");
    }

    buf = (unsigned char *) malloc(out_cap > 0 ? out_cap : 1);
    if (NULL == buf) {
        return __string__init(0, "");
    }

    for (i = 0; i < src_len; ++i) {
        unsigned char c = s[i];
        if (c < 0x80) {
            buf[out_len++] = c;
        } else {
            buf[out_len++] = (unsigned char) (0xC0 | (c >> 6));
            buf[out_len++] = (unsigned char) (0x80 | (c & 0x3F));
        }
    }

    {
        __string__ *dest = __string__init((long long) out_len, (const char *) buf);
        free(buf);

        return dest;
    }
}

__string__ *__compiler_utf8_decode(__string__ *src)
{
    size_t src_len = phpc_strlen(src);
    const unsigned char *s = (const unsigned char *) phpc_strdata(src);
    size_t i = 0;
    unsigned char *buf;
    size_t out_len = 0;

    if (0 == src_len) {
        return __string__init(0, "");
    }

    buf = (unsigned char *) malloc(src_len > 0 ? src_len : 1);
    if (NULL == buf) {
        return __string__init(0, "");
    }

    while (i < src_len) {
        unsigned char c = s[i];
        if (c < 0x80) {
            buf[out_len++] = c;
            ++i;
            continue;
        }
        if ((c & 0xE0) == 0xC0) {
            if (c < 0xC2 || i + 1 >= src_len || (s[i + 1] & 0xC0) != 0x80) {
                buf[out_len++] = '?';
                ++i;
                continue;
            }
            {
                unsigned int cp = ((unsigned int) (c & 0x1F) << 6) | (unsigned int) (s[i + 1] & 0x3F);
                buf[out_len++] = (unsigned char) (cp <= 0xFF ? cp : 0x3F);
            }
            i += 2;
            continue;
        }
        if ((c & 0xF0) == 0xE0) {
            if (i + 2 >= src_len
                || (s[i + 1] & 0xC0) != 0x80
                || (s[i + 2] & 0xC0) != 0x80) {
                buf[out_len++] = '?';
                ++i;
                continue;
            }
            {
                unsigned int cp = ((unsigned int) (c & 0x0F) << 12)
                    | ((unsigned int) (s[i + 1] & 0x3F) << 6)
                    | (unsigned int) (s[i + 2] & 0x3F);
                buf[out_len++] = (unsigned char) (cp >= 0x800 && cp <= 0xFF ? cp : 0x3F);
            }
            i += 3;
            continue;
        }
        if ((c & 0xF8) == 0xF0) {
            if (i + 3 >= src_len
                || (s[i + 1] & 0xC0) != 0x80
                || (s[i + 2] & 0xC0) != 0x80
                || (s[i + 3] & 0xC0) != 0x80) {
                buf[out_len++] = '?';
                ++i;
                continue;
            }
            buf[out_len++] = '?';
            i += 4;
            continue;
        }
        buf[out_len++] = '?';
        ++i;
    }

    {
        __string__ *dest = __string__init((long long) out_len, (const char *) buf);
        free(buf);

        return dest;
    }
}
