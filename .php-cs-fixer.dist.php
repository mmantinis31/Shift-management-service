<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations', __DIR__.'/config', __DIR__.'/tools'])
    ->notName('reference.php')
    ->append([__DIR__.'/public/index.php', __DIR__.'/bin/console']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'blank_line_before_statement' => [
            'statements' => ['if', 'foreach', 'for', 'while', 'switch', 'try', 'return'],
        ],
        'class_definition' => ['single_line' => true, 'space_before_parenthesis' => true],
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'phpunit',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/php-cs-fixer.cache');
