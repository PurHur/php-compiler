/*
 * gettimeofday() runtime for VM/JIT/AOT (issue #3208).
 * Uses gettimeofday(3); mirrors ext/standard/microtimers.c array/float forms.
 */

#include <stddef.h>
#include <stdint.h>
#include <sys/time.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern __string__ *__string__init(long long size, const char *value);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);

static __string__ *phpc_cstr_to_string(const char *cstr)
{
    size_t len = 0;
    const char *p = cstr;

    if (NULL == cstr) {
        cstr = "";
        p = cstr;
    }
    while ('\0' != *p) {
        ++len;
        ++p;
    }

    return __string__init((long long) len, cstr);
}

__hashtable__ *__compiler_gettimeofday_array(void)
{
    struct timeval tv;
    struct timezone tz;
    __hashtable__ *ht = __hashtable__alloc();

    if (0 != gettimeofday(&tv, &tz)) {
        tv.tv_sec = 0;
        tv.tv_usec = 0;
        tz.tz_minuteswest = 0;
        tz.tz_dsttime = 0;
    }

    __hashtable__setStringKeyLong(ht, phpc_cstr_to_string("sec"), (long long) tv.tv_sec);
    __hashtable__setStringKeyLong(ht, phpc_cstr_to_string("usec"), (long long) tv.tv_usec);
    __hashtable__setStringKeyLong(
        ht,
        phpc_cstr_to_string("minuteswest"),
        (long long) tz.tz_minuteswest
    );
    __hashtable__setStringKeyLong(ht, phpc_cstr_to_string("dsttime"), (long long) tz.tz_dsttime);

    return ht;
}

double __compiler_gettimeofday_float(void)
{
    struct timeval tv;

    if (0 != gettimeofday(&tv, NULL)) {
        return 0.0;
    }

    return (double) tv.tv_sec + (double) tv.tv_usec / 1000000.0;
}
