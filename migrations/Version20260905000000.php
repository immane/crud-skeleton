<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the default order currency to stores.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE store ADD currency VARCHAR(10) NOT NULL DEFAULT 'CNY' AFTER timezone");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store DROP currency');
    }
}
