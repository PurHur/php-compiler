/*
 * memory_get_usage() / memory_get_peak_usage() runtime for JIT/AOT (issue #3134).
 * php-src reference: ext/standard/basic_functions.c PHP_FUNCTION(memory_get_usage)
 */

#include <stddef.h>

#if defined(__linux__) || defined(__GLIBC__)
#include <malloc.h>
#endif

typedef struct __value__ __value__;

extern void __value__writeLong(__value__ *out, long long v);

static long long g_peak_emalloc = 0;
static long long g_peak_real = 0;

static long long phpc_read_mallinfo(void)
{
#if defined(__linux__) || defined(__GLIBC__)
#if defined(__GLIBC__) && __GLIBC_PREREQ(2, 33)
    struct mallinfo2 mi = mallinfo2();
    return (long long) mi.uordblks;
#else
    struct mallinfo mi = mallinfo();
    return (long long) mi.uordblks;
#endif
#else
    return 0;
#endif
}

static long long phpc_heap_usage(int real_usage)
{
    long long usage = phpc_read_mallinfo();

    if (real_usage) {
        if (usage > g_peak_real) {
            g_peak_real = usage;
        }
        return usage;
    }
    /* emalloc subset approximation: heap in use via mallinfo (PHPT NOTES). */
    if (usage > g_peak_emalloc) {
        g_peak_emalloc = usage;
    }
    return usage;
}

void __compiler_memory_get_usage(long long real_usage, __value__ *out)
{
    if (NULL == out) {
        return;
    }
    __value__writeLong(out, phpc_heap_usage((int) real_usage));
}

void __compiler_memory_get_peak_usage(long long real_usage, __value__ *out)
{
    if (NULL == out) {
        return;
    }
    (void) phpc_heap_usage((int) real_usage);
    __value__writeLong(out, real_usage ? g_peak_real : g_peak_emalloc);
}
