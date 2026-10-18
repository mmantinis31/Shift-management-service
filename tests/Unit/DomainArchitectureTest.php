<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DomainArchitectureTest extends TestCase
{
    public function testDomainDoesNotReferenceFrameworksOrOuterLayers(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            dirname(__DIR__, 2).'/src/Domain',
            \FilesystemIterator::SKIP_DOTS,
        ));
        $forbidden = ['Doctrine\\', 'Symfony\\', 'App\\Adapter\\', 'App\\Application\\', 'App\\UI\\'];
        $violations = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);

            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');

                foreach ($forbidden as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $violations[] = sprintf('%s: %s', $file->getFilename(), $name);
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Domain dependencies must point inward.');
    }
}
