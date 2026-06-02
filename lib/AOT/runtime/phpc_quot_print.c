/*
 * quoted_printable_encode() / quoted_printable_decode() for JIT/AOT.
 * php-src: ext/standard/quot_print.c — php_quot_print_encode(), PHP_FUNCTION decode.
 */

#include <ctype.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

#define PHP_QPRINT_MAXL 75

static size_t qp_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const unsigned char *qp_strdata(__string__ *s)
{
    if (NULL == s) {
        return (const unsigned char *) "";
    }

    return (const unsigned char *) s + sizeof(void *) + sizeof(long long);
}

static unsigned char qp_hex2int(unsigned char c)
{
    if (c >= '0' && c <= '9') {
        return (unsigned char) (c - '0');
    }
    if (c >= 'A' && c <= 'F') {
        return (unsigned char) (c - 'A' + 10);
    }

    return (unsigned char) (c - 'a' + 10);
}

static int qp_iscntrl(unsigned char c)
{
    return (c < 32) || (c == 127);
}

__string__ *__compiler_quoted_printable_encode(__string__ *str)
{
    size_t length = qp_strlen(str);
    const unsigned char *src = qp_strdata(str);

    if (0 == length) {
        return __string__init(0, "");
    }

    size_t cap = length + ((3 * length) / (PHP_QPRINT_MAXL - 9)) + 4;
    unsigned char *buf = (unsigned char *) malloc(cap > 0 ? cap : 4);
    unsigned char *d;
    const char *hex = "0123456789ABCDEF";
    unsigned long lp = 0;

    if (NULL == buf) {
        return __string__init(0, "");
    }
    d = buf;

    while (length > 0) {
        unsigned char c = *src++;
        length--;

        if (c == '\015' && length > 0 && *src == '\012') {
            *d++ = '\015';
            *d++ = *src++;
            length--;
            lp = 0;
            continue;
        }

        if (
            qp_iscntrl(c) || (c == 0x7f) || (c & 0x80) || (c == '=')
            || ((c == ' ') && length > 0 && *src == '\015')
        ) {
            if (
                (((lp += 3) > PHP_QPRINT_MAXL) && (c <= 0x7f))
                || ((c > 0x7f) && (c <= 0xdf) && ((lp + 3) > PHP_QPRINT_MAXL))
                || ((c > 0xdf) && (c <= 0xef) && ((lp + 6) > PHP_QPRINT_MAXL))
                || ((c > 0xef) && (c <= 0xf4) && ((lp + 9) > PHP_QPRINT_MAXL))
            ) {
                *d++ = '=';
                *d++ = '\015';
                *d++ = '\012';
                lp = 3;
            }
            *d++ = '=';
            *d++ = (unsigned char) hex[c >> 4];
            *d++ = (unsigned char) hex[c & 0xf];
        } else {
            if ((++lp) > PHP_QPRINT_MAXL) {
                *d++ = '=';
                *d++ = '\015';
                *d++ = '\012';
                lp = 1;
            }
            *d++ = c;
        }
    }

    *d = '\0';

    {
        size_t out_len = (size_t) (d - buf);
        __string__ *dest = __string__init((long long) out_len, (const char *) buf);
        free(buf);

        return dest;
    }
}

__string__ *__compiler_quoted_printable_decode(__string__ *arg)
{
    size_t in_len = qp_strlen(arg);
    const unsigned char *str_in = qp_strdata(arg);

    if (0 == in_len) {
        return __string__init(0, "");
    }

    unsigned char *out = (unsigned char *) malloc(in_len + 1);
    size_t i = 0;
    size_t j = 0;

    if (NULL == out) {
        return __string__init(0, "");
    }

    while (str_in[i]) {
        if ('=' == str_in[i]) {
            if (
                str_in[i + 1] && str_in[i + 2]
                && isxdigit(str_in[i + 1]) && isxdigit(str_in[i + 2])
            ) {
                out[j++] = (unsigned char) ((qp_hex2int(str_in[i + 1]) << 4) + qp_hex2int(str_in[i + 2]));
                i += 3;
            } else {
                size_t k = 1;
                while (str_in[i + k] && ((str_in[i + k] == ' ') || (str_in[i + k] == '\t'))) {
                    k++;
                }
                if (!str_in[i + k]) {
                    i += k;
                } else if ((str_in[i + k] == '\r') && (str_in[i + k + 1] == '\n')) {
                    i += k + 2;
                } else if ((str_in[i + k] == '\r') || (str_in[i + k] == '\n')) {
                    i += k + 1;
                } else {
                    out[j++] = str_in[i++];
                }
            }
        } else {
            out[j++] = str_in[i++];
        }
    }

    out[j] = '\0';

    {
        __string__ *dest = __string__init((long long) j, (const char *) out);
        free(out);

        return dest;
    }
}
