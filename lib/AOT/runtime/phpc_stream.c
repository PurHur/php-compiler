/*
 * Stream handle helpers for AOT/JIT fwrite() (issue #1070).
 */

#include <stdint.h>
#include <stdio.h>
#include <string.h>

typedef struct __string__ __string__;

#define PHPC_MAX_STREAM_HANDLES 256

static FILE *phpc_stream_handles[PHPC_MAX_STREAM_HANDLES];

static size_t phpc_string_len(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_string_data(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static FILE *phpc_resolve_stream(int64_t handle)
{
    if (0 == handle) {
        return stderr;
    }
    if (handle > 0 && handle < PHPC_MAX_STREAM_HANDLES && NULL != phpc_stream_handles[handle]) {
        return phpc_stream_handles[handle];
    }

    return NULL;
}

int64_t __compiler_fwrite(int64_t handle, __string__ *data, int64_t length)
{
    FILE *fp = phpc_resolve_stream(handle);
    if (NULL == fp || NULL == data) {
        return -1;
    }

    size_t data_len = phpc_string_len(data);
    size_t write_len = data_len;
    if (length >= 0 && (size_t) length < data_len) {
        write_len = (size_t) length;
    }
    if (0 == write_len) {
        return 0;
    }

    size_t n = fwrite(phpc_string_data(data), 1, write_len, fp);
    if (n != write_len) {
        return -1;
    }

    return (int64_t) n;
}
