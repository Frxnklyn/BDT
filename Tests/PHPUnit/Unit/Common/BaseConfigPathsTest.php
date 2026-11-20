<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\Behat\Common\BdtPaths;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps YAML path literals aligned with the PHP source of truth because YAML cannot use constants.
 */
class BaseConfigPathsTest extends TestCase
{
    /**
     * Checks every nested path so future YAML additions cannot silently bypass the folder contract.
     */
    public function testBaseConfigDataPathsUseRegisteredFolders(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 4) . '/Behat/BaseConfig.yml');
        $this->assertIsArray($config);

        $folders = [];
        foreach ((new ReflectionClass(BdtPaths::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'FOLDER_')) {
                $folders[] = $value;
            }
        }

        $base = $this->normalizePath(BdtPaths::relative());
        $found = [];
        $values = new RecursiveIteratorIterator(new RecursiveArrayIterator($config));
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $path = $this->normalizePath($value);
            if (! str_starts_with($path, $base . '/')) {
                continue;
            }

            $keys = [];
            for ($depth = 0; $depth <= $values->getDepth(); $depth++) {
                $keys[] = $values->getSubIterator($depth)->key();
            }
            $key = implode('.', $keys);
            $segments = explode('/', $path);
            $this->assertSame($base, implode('/', array_slice($segments, 0, 3)), $key);
            $this->assertContains($segments[3], $folders, $key . ' uses an unregistered BDT folder');
            $found[] = $key;
        }

        foreach ([
            'default.formatters.html.output_path',
            'default.gherkin.cache',
            'default.extensions.axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension.browser.config.user_data_dir'
        ] as $key) {
            $this->assertContains($key, $found, $key . ' was not checked as a BDT data path');
        }
    }

    /**
     * Compares YAML and OS-specific PHP paths without changing production separator behavior.
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $placeholder = '%paths.base%/';
        return str_starts_with($path, $placeholder) ? substr($path, strlen($placeholder)) : $path;
    }
}