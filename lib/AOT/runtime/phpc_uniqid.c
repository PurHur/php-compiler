/*
 * uniqid() runtime for VM/JIT/AOT (issue #2219).
 * Uses gettimeofday(3); no PHP internal wrappers.
 */

#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <sys/time.h>

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

__string__ *__compiler_uniqid(__string__ *prefix, int8_t more_entropy)
{
    struct timeval tv;
    const char *pfx;
    size_t pfx_len;
    char core[32];
    char outbuf[512];
    int core_len;
    int total;

    if (NULL == prefix) {
        pfx = "";
        pfx_len = 0;
    } else {
        pfx = phpc_strdata(prefix);
        pfx_len = phpc_strlen(prefix);
    }

    if (0 != gettimeofday(&tv, NULL)) {
        tv.tv_sec = 0;
        tv.tv_usec = 0;
    }

    core_len = snprintf(
        core,
        sizeof(core),
        "%08x%05x",
        (unsigned) tv.tv_sec,
        (unsigned) ((int) (tv.tv_usec % 0x100000))
    );
    if (core_len < 0) {
        return __string__init(1, "0");
    }

    if (0 != more_entropy) {
        unsigned int dec = (unsigned) (
            (uint32_t) tv.tv_usec ^ (uint32_t) tv.tv_sec ^ (uint32_t) pfx_len
        ) % 100000000U;

        core_len += snprintf(
            core + core_len,
            sizeof(core) - (size_t) core_len,
            ".%08u",
            dec
        );
        if (core_len < 0 || (size_t) core_len >= sizeof(core)) {
            core_len = (int) strlen(core);
        }
    }

    if (pfx_len >= sizeof(outbuf) - 1) {
        return __string__init((long long) core_len, core);
    }
    total = snprintf(outbuf, sizeof(outbuf), "%.*s%s", (int) pfx_len, pfx, core);
    if (total < 0) {
        return __string__init((long long) core_len, core);
    }
    if ((size_t) total >= sizeof(outbuf)) {
        total = (int) sizeof(outbuf) - 1;
        outbuf[total] = '\0';
    }

    return __string__init((long long) total, outbuf);
}
