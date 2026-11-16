<?php
namespace axenox\BDT\Tests\PHPUnit;

use axenox\BDT\Facades\MarkdownPrinters\BdtFeatureMarkdownPrinter;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

class BdtFeatureMarkdownPrinterTest extends TestCase
{
    /**
     * Rejects traversal, absolute paths and non-feature selectors.
     * @dataProvider invalidPaths
     */
    public function testRejectsInvalidPaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BdtFeatureMarkdownPrinter($this->createMock(WorkbenchInterface::class), $path);
    }

    /**
     * Provides invalid selectors without reading arbitrary installation files.
     */
    public static function invalidPaths(): array
    {
        return [
            ['../secret.feature'], ['axenox/bdt/../../secret.feature'],
            ['C:\\secret.feature'], ['axenox/bdt/composer.json'], ['0x1234'],
            ['axenox/bdt/test.feature' . "\0"]
        ];
    }

    /**
     * Reads the packaged feature definition without requiring a test run or database.
     */
    public function testPrintsFeatureFile(): void
    {
        $workbench = new Workbench(['MONITOR.ENABLED' => false]);
        $printer = new BdtFeatureMarkdownPrinter(
            $workbench,
            'axenox/bdt/Tests/Behat/Features/Administration/core-smoke.feature'
        );
        $markdown = $printer->getMarkdown();
        self::assertStringContainsString('# core', $markdown);
        self::assertStringContainsString('```gherkin', $markdown);
        self::assertStringContainsString('Feature: Core administration is accessible', $markdown);
        self::assertStringContainsString('Scenario: Open the Core logs page', $markdown);
    }
}
