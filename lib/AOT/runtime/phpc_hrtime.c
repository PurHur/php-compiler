/*
 * hrtime() runtime for VM/JIT/AOT (issue #3195).
 * Monotonic clock via clock_gettime(3); no PHP internal wrappers.
 */

#include <stdint.h>
#include <time.h>

typedef struct __hashtable__ __hashtable__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);

#ifndef CLOCK_MONOTONIC
#ifdef CLOCK_MONOTONIC_RAW
#define CLOCK_MONOTONIC CLOCK_MONOTONIC_RAW
#else
#define CLOCK_MONOTONIC CLOCK_REALTIME
#endif
#endif

static int phpc_hrtime_read(struct timespec *ts)
{
    if (NULL == ts) {
        return -1;
    }

    return clock_gettime(CLOCK_MONOTONIC, ts);
}

long long __compiler_hrtime_ns(void)
{
    struct timespec ts;

    if (0 != phpc_hrtime_read(&ts)) {
        return 0;
    }

    return (long long) ts.tv_sec * 1000000000LL + (long long) ts.tv_nsec;
}

__hashtable__ *__compiler_hrtime_pair(void)
{
    struct timespec ts;
    __hashtable__ *ht = __hashtable__alloc();

    if (NULL == ht) {
        return NULL;
    }

    if (0 != phpc_hrtime_read(&ts)) {
        __hashtable__setLongAt(ht, 0, 0);
        __hashtable__setLongAt(ht, 1, 0);

        return ht;
    }

    __hashtable__setLongAt(ht, 0, (long long) ts.tv_sec);
    __hashtable__setLongAt(ht, 1, (long long) ts.tv_nsec);

    return ht;
}
