/**
 * Store process argc/argv for native M3 emit / compiled compile driver (#1937, #2697).
 */
#include <stddef.h>
#include <string.h>

static int phpc_cli_argc = 0;
static char **phpc_cli_argv = NULL;

void __phpc_cli_store_argv(int argc, char **argv)
{
    phpc_cli_argc = argc;
    phpc_cli_argv = argv;
}

long long __phpc_cli_argc(void)
{
    return (long long) phpc_cli_argc;
}

char *__phpc_cli_argv_cstr(int index)
{
    if (NULL == phpc_cli_argv || index < 0 || index >= phpc_cli_argc) {
        return NULL;
    }

    return phpc_cli_argv[index];
}

int __phpc_cli_str_eq(const char *a, const char *b)
{
    if (NULL == a || NULL == b) {
        return 0;
    }

    return 0 == strcmp(a, b);
}
