/*
 * convert_uuencode() / convert_uudecode() runtime for AOT/JIT.
 * php-src: ext/standard/uuencode.c — php_uuencode(), php_uudecode()
 */

#include <math.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeString(__value__ *out, __string__ *s);
extern void __value__writeBool(__value__ *out, int value);
extern void __compiler_trigger_error(const char *message, size_t len, int level);

#define UU_ENC(c) ((c) ? (char) ((((c) & 077) + ' ')) : '`')
#define UU_ENC_C2(c) UU_ENC(((*(c) << 4) & 060) | ((*((c) + 1) >> 4) & 017))
#define UU_ENC_C3(c) UU_ENC(((*(c + 1) << 2) & 074) | ((*((c) + 2) >> 6) & 03))
#define UU_DEC(c) (((c) - ' ') & 077)

#define UUDEC_ERR_MSG "convert_uudecode(): Argument #1 ($data) is not a valid uuencoded string"
#define UUDEC_ERR_LEVEL 2

static size_t uu_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *uu_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

__string__ *__compiler_convert_uuencode(__string__ *src)
{
    size_t src_len = uu_strlen(src);
    const unsigned char *s = (const unsigned char *) uu_strdata(src);
    const unsigned char *e = s + src_len;
    size_t cap = (src_len / 2) * 3 + 46;
    unsigned char *buf = (unsigned char *) malloc(cap > 0 ? cap : 4);
    unsigned char *p;
    size_t len;

    if (NULL == buf) {
        return __string__init(0, "");
    }
    p = buf;
    len = 45;

    while ((s + 3) < e) {
        const unsigned char *ee = s + len;
        if (ee > e) {
            ee = e;
            len = (size_t) (ee - s);
            if (len % 3) {
                ee = s + (size_t) (floor((double) len / 3.0) * 3);
            }
        }
        *p++ = UU_ENC(len);

        while (s < ee) {
            *p++ = UU_ENC(*s >> 2);
            *p++ = UU_ENC_C2(s);
            *p++ = UU_ENC_C3(s);
            *p++ = UU_ENC(*(s + 2) & 077);
            s += 3;
        }

        if (45 == len) {
            *p++ = '\n';
        }
    }

    if (s < e) {
        if (45 == len) {
            *p++ = UU_ENC(e - s);
            len = 0;
        }

        *p++ = UU_ENC(*s >> 2);
        *p++ = UU_ENC_C2(s);
        *p++ = ((e - s) > 1) ? UU_ENC_C3(s) : UU_ENC('\0');
        *p++ = ((e - s) > 2) ? UU_ENC(*(s + 2) & 077) : UU_ENC('\0');
    }

    if (len < 45) {
        *p++ = '\n';
    }

    *p++ = UU_ENC('\0');
    *p++ = '\n';

    {
        size_t out_len = (size_t) (p - buf);
        __string__ *dest = __string__init((long long) out_len, (const char *) buf);
        free(buf);

        return dest;
    }
}

static void uudecode_fail(__value__ *out)
{
    __compiler_trigger_error(UUDEC_ERR_MSG, sizeof(UUDEC_ERR_MSG) - 1, UUDEC_ERR_LEVEL);
    __value__writeBool(out, 0);
}

void __compiler_convert_uudecode(__string__ *src, __value__ *out)
{
    size_t src_len = uu_strlen(src);
    const char *s = uu_strdata(src);
    const char *e = s + src_len;
    char *buf;
    char *p;
    size_t total_len = 0;

    if (0 == src_len) {
        uudecode_fail(out);

        return;
    }

    buf = (char *) malloc((size_t) ceil((double) src_len * 0.75));
    if (NULL == buf) {
        uudecode_fail(out);

        return;
    }
    p = buf;

    while (s < e) {
        size_t len;
        const char *ee;

        len = (size_t) UU_DEC(*s++);
        if (0 == len) {
            break;
        }
        if (len > src_len) {
            goto err;
        }

        total_len += len;

        ee = s + (45 == len ? 60 : (size_t) floor((double) len * 1.33));
        if (ee > e) {
            goto err;
        }

        while (s < ee) {
            if (s + 4 > e) {
                goto err;
            }
            *p++ = (char) (UU_DEC(*s) << 2 | UU_DEC(*(s + 1)) >> 4);
            *p++ = (char) (UU_DEC(*(s + 1)) << 4 | UU_DEC(*(s + 2)) >> 2);
            *p++ = (char) (UU_DEC(*(s + 2)) << 6 | UU_DEC(*(s + 3)));
            s += 4;
        }

        if (len < 45) {
            break;
        }

        s++;
    }

    if ((size_t) (p - buf) < total_len) {
        size_t len = total_len;
        if (len > (size_t) (p - buf)) {
            *p++ = (char) (UU_DEC(*s) << 2 | UU_DEC(*(s + 1)) >> 4);
            if (len > 1) {
                *p++ = (char) (UU_DEC(*(s + 1)) << 4 | UU_DEC(*(s + 2)) >> 2);
                if (len > 2) {
                    *p++ = (char) (UU_DEC(*(s + 2)) << 6 | UU_DEC(*(s + 3)));
                }
            }
        }
    }

    {
        __string__ *dest = __string__init((long long) total_len, buf);
        free(buf);
        __value__writeString(out, dest);

        return;
    }

err:
    free(buf);
    uudecode_fail(out);
}
