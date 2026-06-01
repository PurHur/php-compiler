/*
 * get_defined_functions() internal bucket for JIT/AOT (issue #3128).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_defined_functions)
 */

#include <stddef.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);
extern __string__ *__string__init(long long size, const char *value);

#include "builtin_function_names.inc"

static __string__ *cstr_to_string(const char *cstr)
{
    size_t len = strlen(cstr);

    return __string__init((long long) len, cstr);
}

static __hashtable__ *build_internal_ht(void)
{
    __hashtable__ *ht = __hashtable__alloc();
    size_t index = 0;

    for (size_t i = 0; i < phpc_builtin_functions_count; ++i) {
        __hashtable__setStringAt(ht, index, cstr_to_string(phpc_builtin_functions[i]));
        ++index;
    }

    return ht;
}

__hashtable__ *__compiler_get_defined_functions_merge(__hashtable__ *user_ht)
{
    __hashtable__ *root = __hashtable__alloc();

    __hashtable__setStringKeyHashtable(root, cstr_to_string("internal"), build_internal_ht());
    if (NULL != user_ht) {
        __hashtable__setStringKeyHashtable(root, cstr_to_string("user"), user_ht);
    } else {
        __hashtable__setStringKeyHashtable(root, cstr_to_string("user"), __hashtable__alloc());
    }

    return root;
}
