<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand currency columns to VARCHAR(32) to support extended codes (e.g. REWARD_POINT)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_order MODIFY currency VARCHAR(32) NOT NULL DEFAULT \'CNY\'');
        $this->addSql('ALTER TABLE payment_invoice MODIFY currency VARCHAR(32) NOT NULL DEFAULT \'CNY\'');
        $this->addSql('ALTER TABLE store_order MODIFY currency VARCHAR(32) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_order MODIFY currency VARCHAR(10) NOT NULL DEFAULT \'CNY\'');
        $this->addSql('ALTER TABLE payment_invoice MODIFY currency VARCHAR(10) NOT NULL DEFAULT \'CNY\'');
        $this->addSql('ALTER TABLE store_order MODIFY currency VARCHAR(10) NOT NULL');
    }
}
