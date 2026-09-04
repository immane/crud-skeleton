<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Snapshot Store verification requirements on Store orders.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_order ADD verification_required TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_order DROP verification_required');
    }
}
