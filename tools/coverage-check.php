<?php

declare(strict_types=1);

/**
 * Enforces line-coverage thresholds from a Clover XML report.
 *
 * Usage: php tools/coverage-check.php build/coverage.xml
 *
 * A threshold directory that does not exist yet is skipped. A directory that
 * exists on disk but is missing from the report fails the check, so a broken
 * coverage configuration cannot pass silently.
 */

/**
 * Minimum line coverage, in whole percent, per project-relative directory.
 */
const COVERAGE_THRESHOLDS = [
    'src' => 90,
    'src/Money' => 100,
    'src/Security' => 100,
    'src/Validation' => 100,
    'src/Webhooks' => 100,
];

/**
 * @return array<string, array{statements: int, covered: int}> keyed by project-relative file path
 */
function coverageLoadFileMetrics(string $cloverPath, string $projectRoot): array
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($cloverPath, SimpleXMLElement::class, \LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        throw new RuntimeException(\sprintf('Unable to parse Clover report "%s".', $cloverPath));
    }

    $files = $xml->xpath('//file');
    if (!\is_array($files)) {
        throw new RuntimeException('Clover report has no <file> elements.');
    }

    $prefix = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/';
    $metrics = [];

    foreach ($files as $file) {
        $name = str_replace('\\', '/', (string) $file->attributes()?->name);
        if (str_starts_with($name, $prefix)) {
            $name = substr($name, \strlen($prefix));
        }

        $fileMetrics = $file->metrics;
        if ($fileMetrics === null) {
            throw new RuntimeException(\sprintf('Clover report has no <metrics> for "%s".', $name));
        }

        $metrics[$name] = [
            'statements' => (int) (string) $fileMetrics->attributes()?->statements,
            'covered' => (int) (string) $fileMetrics->attributes()?->coveredstatements,
        ];
    }

    return $metrics;
}

/**
 * @param list<string> $arguments
 */
function coverageCheck(array $arguments): int
{
    $cloverPath = $arguments[1] ?? '';
    if ($cloverPath === '' || !is_file($cloverPath)) {
        fwrite(\STDERR, "Usage: php tools/coverage-check.php <clover.xml>\nClover report not found.\n");

        return 2;
    }

    $projectRoot = \dirname(__DIR__);

    try {
        $files = coverageLoadFileMetrics($cloverPath, $projectRoot);
    } catch (RuntimeException $e) {
        fwrite(\STDERR, $e->getMessage() . "\n");

        return 2;
    }

    $failed = false;

    foreach (COVERAGE_THRESHOLDS as $directory => $threshold) {
        $statements = 0;
        $covered = 0;
        $fileCount = 0;

        foreach ($files as $path => $fileMetrics) {
            if (str_starts_with($path, $directory . '/')) {
                $statements += $fileMetrics['statements'];
                $covered += $fileMetrics['covered'];
                ++$fileCount;
            }
        }

        if ($fileCount === 0) {
            if (is_dir($projectRoot . '/' . $directory)) {
                printf("%-16s FAIL  directory exists but is missing from the report\n", $directory);
                $failed = true;
            } else {
                printf("%-16s skip  (directory does not exist yet)\n", $directory);
            }

            continue;
        }

        if ($statements === 0) {
            printf("%-16s ok    no executable lines (%d files)\n", $directory, $fileCount);

            continue;
        }

        $passes = $covered * 100 >= $threshold * $statements;
        printf(
            "%-16s %-5s %6.2f%% (%d/%d lines), required %d%%\n",
            $directory,
            $passes ? 'ok' : 'FAIL',
            $covered * 100 / $statements,
            $covered,
            $statements,
            $threshold,
        );
        $failed = $failed || !$passes;
    }

    return $failed ? 1 : 0;
}

$arguments = $_SERVER['argv'] ?? [];
exit(coverageCheck(\is_array($arguments) ? array_values(array_filter($arguments, is_string(...))) : []));
