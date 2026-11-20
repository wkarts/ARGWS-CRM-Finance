#!/bin/sh
set -eu
module_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
php_binary=${PHP_BIN:-php}
export PHP_BIN="$php_binary"
completed_suites=0
for suite in "$module_root"/tests/*_test.php; do
    case "$(basename "$suite")" in
        database_matrix_test.php|sync_queue_database_test.php|automation_concurrency_database_test.php|rates_permissions_database_test.php|sync_regression_database_test.php) continue ;;
    esac
    "$php_binary" "$suite"
    completed_suites=$((completed_suites + 1))
done
printf 'OK: %s suítes PHP concluídas.\n' "$completed_suites"
if [ -n "${NFSE_TEST_MYSQL_PORT:-}" ]; then
    for scenario in native_prefix native_empty multi_database multi_prefix; do
        "$php_binary" "$module_root/tests/database_matrix_test.php" "$scenario"
    done
    "$php_binary" "$module_root/tests/sync_queue_database_test.php"
    "$php_binary" "$module_root/tests/automation_concurrency_database_test.php"
    "$php_binary" "$module_root/tests/rates_permissions_database_test.php"
    "$php_binary" "$module_root/tests/sync_regression_database_test.php"
else
    printf 'PENDENTE nesta chamada: matriz SQL, sincronização durável e concorrência; configure uma instância local descartável e NFSE_TEST_MYSQL_PORT.\n'
fi
