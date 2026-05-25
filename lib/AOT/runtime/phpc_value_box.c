/*
 * Minimal __value__ writers for AOT C runtime (session, ob, etc.).
 * LLVM JIT inlines these for hot paths; standalone C units need this object (#1896).
 */

#include <stddef.h>

typedef struct __value__ {
    char type;
    char value[8];
} __value__;

#define PHPC_TYPE_BOOL 2

void __value__writeBool(__value__ *out, int value)
{
    if (NULL == out) {
        return;
    }
    out->type = PHPC_TYPE_BOOL;
    out->value[0] = value ? 1 : 0;
}
