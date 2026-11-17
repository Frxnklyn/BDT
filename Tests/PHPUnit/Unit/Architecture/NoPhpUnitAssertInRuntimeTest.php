<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Stops runner-dependent assertions from re-entering Behat runtime code.
 */
class NoPhpUnitAssertInRuntimeTest extends TestCase
{
    /**
     * Scans the package, including Tests/Behat, while excluding PHPUnit tests and nested vendors.
     */
    public function testRuntimeDoesNotUsePhpUnitAssert(): void
    {
        $root = dirname(__DIR__, 4);
        $entries = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $entries,
            /**
             * Keeps runtime acceptance contexts in scope while pruning only excluded directories.
             */
            static function (SplFileInfo $entry) use ($root): bool {
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
                return !$entry->isDir()
                    || (strcasecmp($entry->getFilename(), 'vendor') !== 0
                        && strcasecmp($relative, 'Tests/PHPUnit') !== 0);
            }
        );
        $offenders = [];
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if (!$file->isFile() || strcasecmp($file->getExtension(), 'php') !== 0) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                self::fail('Cannot read runtime PHP file: ' . $relative);
            }
            if (preg_match('~use\s+PHPUnit\\\\Framework\\\\Assert\b|\\\\PHPUnit\\\\Framework\\\\Assert\s*::~i', $source)) {
                $offenders[] = $relative;
            }
        }
        sort($offenders);
        self::assertSame(
            [],
            $offenders,
            "PHPUnit Assert is forbidden in runtime code:\n" . implode("\n", $offenders)
                . "\nSee Docs/Developer/Assertions.md."
        );
    }
}
