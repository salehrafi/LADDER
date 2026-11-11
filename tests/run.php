<?php
/**
 * Test runner.
 *
 *   php tests/run.php              unit + integration + HTTP tests
 *   php tests/run.php --no-http    skip HTTP (end-to-end) tests
 *   php tests/run.php --filter=csrf   only tests whose name contains "csrf"
 *
 * HTTP tests need the app served by a web server; set TEST_BASE_URL if it is
 * not http://localhost/Quiz-App. Exit code is non-zero when anything fails.
 */

require __DIR__ . '/bootstrap.php';

$args = array_slice($argv, 1);
$skip_http = in_array('--no-http', $args, true);
$filter = null;
foreach ($args as $a) {
    if (strpos($a, '--filter=') === 0) {
        $filter = strtolower(substr($a, 9));
    }
}

$suites = ['unit', 'integration'];
if (!$skip_http) {
    $suites[] = 'http';
}

foreach ($suites as $suite) {
    foreach (glob(__DIR__ . "/$suite/*Test.php") as $file) {
        $GLOBALS['__current_file'] = "$suite/" . basename($file);
        require $file;
    }
}

$passed = 0;
$failed = [];
$current = null;
$start = microtime(true);

foreach ($GLOBALS['__tests'] as [$file, $name, $fn]) {
    if ($filter !== null && strpos(strtolower($name), $filter) === false) {
        continue;
    }
    if ($file !== $current) {
        echo "\n$file\n";
        $current = $file;
    }
    try {
        $fn();
        $passed++;
        echo "  \u{2713} $name\n";
    } catch (Throwable $e) {
        $failed[] = "$file: $name";
        echo "  \u{2717} $name\n";
        $detail = $e instanceof AssertionFailed
            ? $e->getMessage()
            : get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        echo '      ' . str_replace("\n", "\n", $detail) . "\n";
    }
}

$total = $passed + count($failed);
printf("\n%d tests, %d passed, %d failed (%.2fs)\n", $total, $passed, count($failed), microtime(true) - $start);
exit($failed ? 1 : 0);
