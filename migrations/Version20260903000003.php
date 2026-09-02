<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add verification audit columns to store_order for store fulfillment verification (requireVerification flag).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_order ADD verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE store_order ADD verified_by VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_order ADD verification_code VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_order DROP verified_at');
        $this->addSql('ALTER TABLE store_order DROP verified_by');
        $this->addSql('ALTER TABLE store_order DROP verification_code');
    }
}
