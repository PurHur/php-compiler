/*
 * debug_backtrace() JIT/AOT runtime — minimal compile-time frames (issues #1378, #1056).
 */

#include <stddef.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;

extern __hashtable__ *__hashtable__alloc(void);
extern __string__ *__string__init(long long size, const char *value);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setHashtableAt(__hashtable__ *ht, size_t index, __hashtable__ *child);

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

static __hashtable__ *phpc_debug_frame(__string__ *file, __string__ *function)
{
    __hashtable__ *entry = __hashtable__alloc();

    __hashtable__setStringKeyString(entry, phpc_cstr_to_string("file"), file);
    __hashtable__setStringKeyLong(entry, phpc_cstr_to_string("line"), 0);
    __hashtable__setStringKeyString(entry, phpc_cstr_to_string("function"), function);

    return entry;
}

/*
 * Build a packed list: frame0 = internal call site, optional frame1 = enclosing user function.
 * Empty enclosing function skips the second frame (matches JitDebugBacktrace layout).
 */
__hashtable__ *__compiler_jit_debug_backtrace(
    __string__ *frame0_file,
    __string__ *frame0_function,
    __string__ *frame1_file,
    __string__ *frame1_function,
    int has_frame1
)
{
    __hashtable__ *out = __hashtable__alloc();
    size_t index = 0;

    __hashtable__setHashtableAt(
        out,
        index++,
        phpc_debug_frame(frame0_file, frame0_function)
    );

    if (0 != has_frame1) {
        __hashtable__setHashtableAt(
            out,
            index,
            phpc_debug_frame(
                NULL != frame1_file ? frame1_file : phpc_cstr_to_string(""),
                frame1_function
            )
        );
    }

    return out;
}
