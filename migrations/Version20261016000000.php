<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist invoice refund attempts for idempotent recovery after uncertain gateway outcomes.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('payment_invoice_refund_attempt');
        $table->addColumn('attempt_key', 'string', ['length' => 64]);
        $table->addColumn('uuid', 'string', ['length' => 36]);
        $table->addColumn('invoice_uuid', 'string', ['length' => 36]);
        $table->addColumn('requested_amount', 'bigint');
        $table->addColumn('status', 'string', ['length' => 20]);
        $table->addColumn('gateway_refund_id', 'string', ['length' => 128, 'notnull' => false]);
        $table->addColumn('result', 'json', ['notnull' => false]);
        $table->addColumn('reason', 'text');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable');
        $table->setPrimaryKey(['attempt_key']);
        $table->addUniqueIndex(['uuid'], 'UNIQ_AD5D67AED17F50A6');
        $table->addIndex(['invoice_uuid', 'status'], 'idx_invoice_refund_attempt_invoice_status');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_invoice_refund_attempt');
    }
}
