<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Adapter\Persistence\SqliteConnectionMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class Database
{
    public static function reset(KernelInterface $kernel): Connection
    {
        $connection = $kernel->getContainer()->get('doctrine')->getConnection();
        $expected = $kernel->getProjectDir().'/var/test/shifts_test.sqlite';

        if (!$connection instanceof Connection
            || 'test' !== $kernel->getEnvironment()
            || 'pdo_sqlite' !== ($connection->getParams()['driver'] ?? null)
            || $expected !== ($connection->getParams()['path'] ?? null)) {
            throw new \LogicException('Tests may reset only var/test/shifts_test.sqlite.');
        }
        (new Filesystem())->mkdir(dirname($expected));

        $application = new Application($kernel);
        $command = new CommandTester($application->find('doctrine:migrations:migrate'));
        $command->execute(['--no-interaction' => true], ['interactive' => false]);
        $command->assertCommandIsSuccessful();
        $connection->executeStatement('DELETE FROM shift_notifications');
        $connection->executeStatement('DELETE FROM shifts');

        return $connection;
    }

    public static function connect(?Configuration $configuration = null): Connection
    {
        $parser = new \Doctrine\DBAL\Tools\DsnParser(['sqlite' => 'pdo_sqlite']);
        $configuration ??= new Configuration();
        $configuration->setMiddlewares([new SqliteConnectionMiddleware(), ...$configuration->getMiddlewares()]);

        return \Doctrine\DBAL\DriverManager::getConnection($parser->parse(self::url()), $configuration);
    }

    public static function url(): string
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;

        if (!is_string($url)) {
            throw new \LogicException('The test database URL is not configured.');
        }

        return str_replace('%kernel.project_dir%', dirname(__DIR__, 2), $url);
    }

    public static function count(Connection $connection, string $table): int
    {
        if (!in_array($table, ['shifts', 'shift_notifications'], true)) {
            throw new \InvalidArgumentException('Unknown test table.');
        }
        $count = $connection->fetchOne('SELECT COUNT(*) FROM '.$table);

        if (!is_int($count) && !is_string($count)) {
            throw new \UnexpectedValueException('A row count must be numeric.');
        }

        return (int) $count;
    }
}
