<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the original refund allocation for safe recovery.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('payment_invoice_refund_attempt')->addColumn('allocation', 'json', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('payment_invoice_refund_attempt')->dropColumn('allocation');
    }
}
