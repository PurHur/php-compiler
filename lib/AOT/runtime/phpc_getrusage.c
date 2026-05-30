/*
 * getrusage() runtime for JIT/AOT (issue #3240).
 * php-src reference: ext/standard/basic_functions.c PHP_FUNCTION(getrusage)
 */

#include <stddef.h>
#include <string.h>

#if !defined(_WIN32) && !defined(__MINGW32__)
#include <sys/time.h>
#include <sys/resource.h>
#endif

typedef struct __string__ __string__;
typedef struct __value__ __value__;
typedef struct __hashtable__ __hashtable__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);
extern void __value__writeBool(__value__ *out, int value);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);

static __string__ *gr_key(const char *key)
{
    return __string__init((long long) strlen(key), key);
}

static void gr_set_long(__hashtable__ *ht, const char *key, long long val)
{
    __hashtable__setStringKeyLong(ht, gr_key(key), val);
}

void __compiler_getrusage(long long who, __value__ *out)
{
#if defined(_WIN32) || defined(__MINGW32__)
    if (NULL != out) {
        __value__writeBool(out, 0);
    }
#else
    struct rusage ru;
    __hashtable__ *ht;

    if (NULL == out) {
        return;
    }

    if (getrusage((int) who, &ru) != 0) {
        __value__writeBool(out, 0);
        return;
    }

    ht = __hashtable__alloc();
    if (NULL == ht) {
        return;
    }

    gr_set_long(ht, "ru_oublock", (long long) ru.ru_oublock);
    gr_set_long(ht, "ru_inblock", (long long) ru.ru_inblock);
    gr_set_long(ht, "ru_msgsnd", (long long) ru.ru_msgsnd);
    gr_set_long(ht, "ru_msgrcv", (long long) ru.ru_msgrcv);
    gr_set_long(ht, "ru_maxrss", (long long) ru.ru_maxrss);
    gr_set_long(ht, "ru_ixrss", (long long) ru.ru_ixrss);
    gr_set_long(ht, "ru_idrss", (long long) ru.ru_idrss);
    gr_set_long(ht, "ru_minflt", (long long) ru.ru_minflt);
    gr_set_long(ht, "ru_majflt", (long long) ru.ru_majflt);
    gr_set_long(ht, "ru_nsignals", (long long) ru.ru_nsignals);
    gr_set_long(ht, "ru_nvcsw", (long long) ru.ru_nvcsw);
    gr_set_long(ht, "ru_nivcsw", (long long) ru.ru_nivcsw);
    gr_set_long(ht, "ru_nswap", (long long) ru.ru_nswap);
    gr_set_long(ht, "ru_utime.tv_sec", (long long) ru.ru_utime.tv_sec);
    gr_set_long(ht, "ru_utime.tv_usec", (long long) ru.ru_utime.tv_usec);
    gr_set_long(ht, "ru_stime.tv_sec", (long long) ru.ru_stime.tv_sec);
    gr_set_long(ht, "ru_stime.tv_usec", (long long) ru.ru_stime.tv_usec);

    __value__writeHashtable(out, ht);
#endif
}
