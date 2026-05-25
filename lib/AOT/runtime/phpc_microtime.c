/*
 * microtime() runtime for VM/JIT/AOT (issue #2186).
 * Uses gettimeofday(3); no PHP internal wrappers.
 */

#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <sys/time.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

__string__ *__compiler_microtime_string(void)
{
    struct timeval tv;

    if (0 != gettimeofday(&tv, NULL)) {
        return __string__init(1, "0");
    }

    char buf[64];
    int n = snprintf(
        buf,
        sizeof(buf),
        "%.8f %ld",
        (double) tv.tv_usec / 1000000.0,
        (long) tv.tv_sec
    );
    if (n < 0) {
        return __string__init(1, "0");
    }
    if ((size_t) n >= sizeof(buf)) {
        n = (int) sizeof(buf) - 1;
    }
    buf[n] = '\0';

    return __string__init((long long) n, buf);
}

double __compiler_microtime_float(void)
{
    struct timeval tv;

    if (0 != gettimeofday(&tv, NULL)) {
        return 0.0;
    }

    return (double) tv.tv_sec + (double) tv.tv_usec / 1000000.0;
}
