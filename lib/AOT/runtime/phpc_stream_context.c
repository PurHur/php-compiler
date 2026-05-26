/*
 * stream_context_create() marker for JIT/AOT (issue #1377, #2457).
 */

#include <stdint.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

#define PHPC_STREAM_CTX_MARKER "__phpc_stream_context"

extern __string__ *__string__init(long long size, const char *value);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);

static int phpc_stream_next_id = 0;

static __string__ *phpc_cstr_to_string(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

__hashtable__ *__phpc_stream_context_attach_marker(__hashtable__ *ht)
{
    if (NULL == ht) {
        return ht;
    }
    if (phpc_stream_next_id < 0) {
        phpc_stream_next_id = 0;
    }
    __hashtable__setStringKeyLong(
        ht,
        phpc_cstr_to_string(PHPC_STREAM_CTX_MARKER),
        (long long) ++phpc_stream_next_id
    );

    return ht;
}
