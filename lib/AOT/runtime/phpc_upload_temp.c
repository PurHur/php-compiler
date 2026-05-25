/*
 * Upload temp validation for is_uploaded_file() / move_uploaded_file() (issues #2005, #2204).
 * Uses libc realpath/rename; no PHP internal wrappers.
 */

#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

#define PHPC_UPLOAD_TEMP_PREFIX "phpc_upload_"

static int phpc_path_has_parent_traversal(const char *path)
{
    const char *p;
    const char *start;

    if (NULL == path) {
        return 1;
    }
    start = path;
    for (p = path; ; p++) {
        if ('\0' == *p || '/' == *p) {
            size_t len = (size_t) (p - start);
            if (2 == len && 0 == strncmp(start, "..", 2)) {
                return 1;
            }
            if ('\0' == *p) {
                break;
            }
            start = p + 1;
        }
    }

    return 0;
}

static int phpc_is_valid_upload_temp(const char *path)
{
    char resolved[PATH_MAX];
    char tmpdir[PATH_MAX];
    const char *base;
    const char *dir;
    char *real_from;
    char *real_tmp;
    size_t tmp_len;

    if (NULL == path || '\0' == path[0] || phpc_path_has_parent_traversal(path)) {
        return 0;
    }
    base = strrchr(path, '/');
    base = (NULL != base) ? base + 1 : path;
    if (0 != strncmp(base, PHPC_UPLOAD_TEMP_PREFIX, strlen(PHPC_UPLOAD_TEMP_PREFIX))) {
        return 0;
    }
    real_from = realpath(path, resolved);
    if (NULL == real_from) {
        return 0;
    }
    dir = getenv("TMPDIR");
    if (NULL == dir || '\0' == *dir) {
        dir = getenv("TEMP");
    }
    if (NULL == dir || '\0' == *dir) {
        dir = getenv("TMP");
    }
    if (NULL == dir || '\0' == *dir) {
        dir = "/tmp";
    }
    real_tmp = realpath(dir, tmpdir);
    if (NULL == real_tmp) {
        return 0;
    }
    tmp_len = strlen(real_tmp);
    if (tmp_len + 1 >= sizeof(tmpdir)) {
        return 0;
    }
    if ('/' != real_tmp[tmp_len - 1]) {
        real_tmp[tmp_len] = '/';
        real_tmp[tmp_len + 1] = '\0';
        tmp_len++;
    }
    if (0 != strncmp(real_from, real_tmp, tmp_len)) {
        return 0;
    }

    return 1;
}

/** is_uploaded_file() — validate multipart upload temp path (issue #2204). */
int __compiler_is_uploaded_file(__string__ *path)
{
    if (NULL == path) {
        return 0;
    }

    return phpc_is_valid_upload_temp(phpc_strdata(path));
}

/** move_uploaded_file() — rename upload temp only under system temp (issue #2005). */
int __compiler_move_uploaded_file(__string__ *from, __string__ *to)
{
    const char *src;
    const char *dst;

    if (NULL == from || NULL == to) {
        return 0;
    }
    src = phpc_strdata(from);
    dst = phpc_strdata(to);
    if (!phpc_is_valid_upload_temp(src) || phpc_path_has_parent_traversal(dst) || '\0' == dst[0]) {
        return 0;
    }
    if (0 != rename(src, dst)) {
        return 0;
    }

    return 1;
}
