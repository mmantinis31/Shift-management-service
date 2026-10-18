<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shifts and notification records in SQLite.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SQLitePlatform,
            'This migration requires SQLite.',
        );
        $this->addSql(<<<'SQL'
            CREATE TABLE shifts (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                employee_id VARCHAR(255) NOT NULL,
                country_code VARCHAR(2) NOT NULL,
                shift_date VARCHAR(10) NOT NULL,
                start_time VARCHAR(5) NOT NULL,
                end_time VARCHAR(5) NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_shifts_employee_date_start ON shifts (employee_id, shift_date, start_time)');
        $this->addSql(<<<'SQL'
            CREATE TABLE shift_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                shift_id VARCHAR(32) NOT NULL UNIQUE,
                employee_id VARCHAR(255) NOT NULL,
                shift_date VARCHAR(10) NOT NULL,
                start_time VARCHAR(5) NOT NULL,
                end_time VARCHAR(5) NOT NULL,
                CONSTRAINT fk_notifications_shift FOREIGN KEY (shift_id) REFERENCES shifts (id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shift_notifications');
        $this->addSql('DROP TABLE shifts');
    }
}
