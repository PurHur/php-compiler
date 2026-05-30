/*
 * count_chars() runtime for JIT/AOT (PHP 8 modes 0–4; ext/standard/string.c).
 */

#include <stdlib.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__grow(__hashtable__ *ht, size_t min_cap);
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);
extern __string__ *__string__init(long long size, const char *value);

static void count_histogram(const char *data, size_t len, unsigned long *counts)
{
    size_t i;

    memset(counts, 0, sizeof(unsigned long) * 256);
    if (NULL == data) {
        return;
    }
    for (i = 0; i < len; i++) {
        counts[(unsigned char) data[i]]++;
    }
}

__hashtable__ *__phpc_count_chars_array_bytes(const char *data, size_t len, long long mode)
{
    unsigned long counts[256];
    size_t i;
    __hashtable__ *ht;

    if (mode < 0 || mode > 2) {
        return NULL;
    }
    count_histogram(data, len, counts);
    ht = __hashtable__alloc();
    __hashtable__grow(ht, 256);
    for (i = 0; i < 256; i++) {
        if (0 == mode) {
            __hashtable__grow(ht, i + 1);
            __hashtable__setLongAt(ht, i, (long long) counts[i]);
        } else if (1 == mode && counts[i] > 0) {
            __hashtable__grow(ht, i + 1);
            __hashtable__setLongAt(ht, i, (long long) counts[i]);
        } else if (2 == mode && 0 == counts[i]) {
            __hashtable__grow(ht, i + 1);
            __hashtable__setLongAt(ht, i, 0);
        }
    }

    return ht;
}

__string__ *__phpc_count_chars_string_bytes(const char *data, size_t len, long long mode)
{
    unsigned long counts[256];
    char buf[256];
    size_t out = 0;
    size_t i;

    if (mode != 3 && mode != 4) {
        return __string__init(0, "");
    }
    count_histogram(data, len, counts);
    for (i = 0; i < 256; i++) {
        if ((3 == mode && counts[i] > 0) || (4 == mode && 0 == counts[i])) {
            buf[out++] = (char) i;
        }
    }

    return __string__init((long long) out, buf);
}
