/*
 * uniqid() runtime for VM/JIT/AOT (issue #2219).
 * Matches PHP 8.3+ ext/standard/uniqid.c (gettimeofday poll + optional %.8F entropy).
 */

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/random.h>
#include <sys/time.h>

#define PHPC_UNIQID_USEC_MOD 0x100000

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

static unsigned int phpc_random_u32(void)
{
    unsigned int v = 0;

    if (getrandom(&v, sizeof(v), 0) < 0) {
        v = (unsigned int) rand();
    }

    return v;
}

static int phpc_poll_timeval(struct timeval *tv)
{
    static struct timeval prev_tv = {0, 0};
    int attempts = 0;

    do {
        if (0 != gettimeofday(tv, NULL)) {
            return -1;
        }
        if (++attempts > 1000000) {
            break;
        }
    } while (tv->tv_sec == prev_tv.tv_sec && tv->tv_usec == prev_tv.tv_usec);

    prev_tv.tv_sec = tv->tv_sec;
    prev_tv.tv_usec = tv->tv_usec;

    return 0;
}

__string__ *__compiler_uniqid(__string__ *prefix, int8_t more_entropy)
{
    struct timeval tv;
    const char *pfx;
    int sec;
    int usec;
    char buf[256];
    int n;

    pfx = (NULL == prefix) ? "" : phpc_strdata(prefix);

    if (0 != phpc_poll_timeval(&tv)) {
        unsigned int r1 = phpc_random_u32();
        unsigned int r2 = phpc_random_u32();
        sec = (int) r1;
        usec = (int) (r2 % PHPC_UNIQID_USEC_MOD);
    } else {
        sec = (int) tv.tv_sec;
        usec = (int) (tv.tv_usec % PHPC_UNIQID_USEC_MOD);
    }

    if (more_entropy) {
        unsigned int bytes = phpc_random_u32();
        double seed = ((double) bytes / (double) UINT32_MAX) * 10.0;
        n = snprintf(buf, sizeof(buf), "%s%08x%05x%.8F", pfx, sec, usec, seed);
    } else {
        n = snprintf(buf, sizeof(buf), "%s%08x%05x", pfx, sec, usec);
    }

    if (n < 0) {
        return __string__init(0, "");
    }
    if ((size_t) n >= sizeof(buf)) {
        n = (int) sizeof(buf) - 1;
        buf[n] = '\0';
    }

    return __string__init((long long) n, buf);
}
