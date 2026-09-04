<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add currency column to store (default CNY, existing stores set to LIANSHENG_POINT)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store ADD currency VARCHAR(32) NOT NULL DEFAULT \'CNY\'');
        $this->addSql('UPDATE store SET currency = \'LIANSHENG_POINT\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store DROP currency');
    }
}
