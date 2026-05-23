/*
 * strtr() runtime for AOT/JIT (issue #1030).
 * Two-string byte translation table (PHP subset).
 */

#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static size_t strtr_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *strtr_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

__string__ *__compiler_strtr(__string__ *subject, __string__ *from, __string__ *to)
{
    size_t slen = strtr_strlen(subject);
    const char *sdata = strtr_strdata(subject);
    size_t flen = strtr_strlen(from);
    const char *fdata = strtr_strdata(from);
    size_t tlen = strtr_strlen(to);
    const char *tdata = strtr_strdata(to);

    if (0 == flen) {
        return subject;
    }

    unsigned char table[256];
    for (int i = 0; i < 256; i++) {
        table[i] = (unsigned char) i;
    }
    size_t plen = flen < tlen ? flen : tlen;
    for (size_t i = 0; i < plen; i++) {
        table[(unsigned char) fdata[i]] = (unsigned char) tdata[i];
    }

    if (0 == slen) {
        return __string__init(0, "");
    }

    char *outbuf = (char *) malloc(slen);
    if (NULL == outbuf) {
        return __string__init(0, "");
    }
    for (size_t i = 0; i < slen; i++) {
        outbuf[i] = (char) table[(unsigned char) sdata[i]];
    }
    __string__ *result = __string__init((long long) slen, outbuf);
    free(outbuf);

    return result;
}
