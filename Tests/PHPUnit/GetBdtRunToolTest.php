<?php
namespace axenox\BDT\Tests\PHPUnit;

use axenox\BDT\AI\Tools\GetBdtRunTool;
use axenox\BDT\Facades\MarkdownPrinters\BdtRunMarkdownPrinter;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use PHPUnit\Framework\TestCase;

class GetBdtRunToolTest extends TestCase
{
    /**
     * The tool returns the printer's Markdown and associates all run files with its result.
     *
     * @return void
     */
    public function testReturnsRunMarkdownAndScreenshotAttachments() : void
    {
        $workbench = new Workbench();
        $file = $this->createMock(FileInfoInterface::class);
        $printer = $this->getMockBuilder(BdtRunMarkdownPrinter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMarkdown', 'getFiles'])
            ->getMock();
        $printer->expects(self::once())
            ->method('getMarkdown')
            ->willReturn('# BDT run');
        $printer->expects(self::once())
            ->method('getFiles')
            ->willReturn([$file]);

        $tool = new class($workbench, $printer) extends GetBdtRunTool {
            private BdtRunMarkdownPrinter $printer;
            public ?string $selector = null;

            /**
             * @param Workbench $workbench
             * @param BdtRunMarkdownPrinter $printer
             */
            public function __construct(Workbench $workbench, BdtRunMarkdownPrinter $printer)
            {
                parent::__construct($workbench);
                $this->printer = $printer;
            }

            /**
             * @param string $selector
             * @return BdtRunMarkdownPrinter
             */
            protected function createPrinter(string $selector) : BdtRunMarkdownPrinter
            {
                $this->selector = $selector;
                return $this->printer;
            }
        };

        $result = $tool->invoke(
            $this->createMock(AiTaskHandlerInterface::class),
            $this->createMock(TaskInterface::class),
            ['@smoke']
        );

        self::assertSame('@smoke', $tool->selector);
        self::assertSame('# BDT run', $result->getValue());
        self::assertInstanceOf(AiToolResultInterface::class, $result);
        self::assertSame([$file], $result->getAttachments());
    }
}
