/*
 * fwrite() runtime for AOT/JIT (issue #1070).
 * Handles STDOUT/STDERR via write(2); fopen handles via an internal FILE* table.
 */

#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

typedef struct __string__ __string__;

#define PHPC_STDIO_TABLE_SIZE 64
#define PHPC_STDIO_HANDLE_BASE 100

static FILE *phpc_stdio_table[PHPC_STDIO_TABLE_SIZE];
static long long phpc_stdio_next = PHPC_STDIO_HANDLE_BASE;

static size_t phpc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static FILE *phpc_stdio_lookup(long long handle)
{
    long long idx;

    if (handle < PHPC_STDIO_HANDLE_BASE) {
        return NULL;
    }
    idx = handle - PHPC_STDIO_HANDLE_BASE;
    if (idx < 0 || idx >= PHPC_STDIO_TABLE_SIZE) {
        return NULL;
    }

    return phpc_stdio_table[idx];
}

/** fopen() runtime: returns handle id (>=100) or 0 on failure. */
long long __compiler_fopen(__string__ *path, __string__ *mode)
{
    const char *p;
    const char *m;
    FILE *fp;
    long long slot;
    long long i;

    if (NULL == path || NULL == mode) {
        return 0;
    }
    p = phpc_strdata(path);
    m = phpc_strdata(mode);
    fp = fopen(p, m);
    if (NULL == fp) {
        return 0;
    }
    for (i = 0; i < PHPC_STDIO_TABLE_SIZE; i++) {
        if (NULL == phpc_stdio_table[i]) {
            phpc_stdio_table[i] = fp;
            slot = PHPC_STDIO_HANDLE_BASE + i;
            if (slot >= phpc_stdio_next) {
                phpc_stdio_next = slot + 1;
            }

            return slot;
        }
    }
    fclose(fp);

    return 0;
}

/** fclose() runtime: returns 1 on success, 0 on failure. */
int __compiler_fclose(long long handle)
{
    FILE *fp;
    long long idx;

    if (handle < PHPC_STDIO_HANDLE_BASE) {
        return 0;
    }
    idx = handle - PHPC_STDIO_HANDLE_BASE;
    if (idx < 0 || idx >= PHPC_STDIO_TABLE_SIZE) {
        return 0;
    }
    fp = phpc_stdio_table[idx];
    if (NULL == fp) {
        return 0;
    }
    phpc_stdio_table[idx] = NULL;
    if (0 != fclose(fp)) {
        return 0;
    }

    return 1;
}

/**
 * fwrite() runtime: returns bytes written, or -1 on failure.
 * handle 1 = stdout, 2 = stderr; length < 0 writes the full string.
 */
long long __compiler_fwrite(long long handle, __string__ *data, long long length)
{
    const char *buf;
    size_t data_len;
    size_t to_write;
    ssize_t n;
    FILE *fp;
    size_t written;

    if (NULL == data) {
        return -1;
    }
    buf = phpc_strdata(data);
    data_len = phpc_strlen(data);
    if (length >= 0 && (size_t) length < data_len) {
        to_write = (size_t) length;
    } else {
        to_write = data_len;
    }
    if (0 == to_write) {
        return 0;
    }

    if (1 == handle || 2 == handle) {
        n = write((int) handle, buf, to_write);
        if (n < 0) {
            return -1;
        }

        return (long long) n;
    }

    fp = phpc_stdio_lookup(handle);
    if (NULL == fp) {
        return -1;
    }
    written = fwrite(buf, 1, to_write, fp);
    if (written != to_write) {
        return -1;
    }

    return (long long) written;
}
