<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915000003 extends AbstractMigration
{
    /** @var array<string, string> */
    private const TABLES = [
        'identity_refresh_token' => 'uniq_identity_refresh_token_uuid',
        'authorization_audit_log' => 'uniq_authorization_audit_log_uuid',
        'authorization_role_field_grant' => 'uniq_authorization_role_field_grant_uuid',
        'store_membership' => 'uniq_store_membership_uuid',
        'store_trade_order_cancellation' => 'uniq_store_trade_order_cancellation_uuid',
        'store_consumed_event' => 'uniq_store_consumed_event_uuid',
        'store_outbox_message' => 'uniq_store_outbox_message_uuid',
        'trade_order_store_lifecycle' => 'uniq_trade_order_store_lifecycle_entity_uuid',
        'trade_consumed_event' => 'uniq_trade_consumed_event_uuid',
        'trade_outbox_message' => 'uniq_trade_outbox_message_uuid',
        'inventory_stock' => 'uniq_inventory_stock_uuid',
        'inventory_recipe_line' => 'uniq_inventory_recipe_line_uuid',
        'inventory_reservation_line' => 'uniq_inventory_reservation_line_uuid',
        'inventory_consumed_event' => 'uniq_inventory_consumed_event_uuid',
        'inventory_outbox_message' => 'uniq_inventory_outbox_message_uuid',
        'settlement_consumed_event' => 'uniq_settlement_consumed_event_uuid',
        'settlement_outbox_message' => 'uniq_settlement_outbox_message_uuid',
        'wallet_voucher_comment' => 'uniq_wallet_voucher_comment_uuid',
    ];

    public function getDescription(): string
    {
        return 'UUID rollout step 4: add nullable UUID columns to remaining entities';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $tableName => $_) {
            if (!$schema->getTable($tableName)->hasColumn('uuid')) {
                $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN uuid VARCHAR(36) DEFAULT NULL', $tableName));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $platformClass = $this->connection->getDatabasePlatform()::class;
        $usesStandaloneDropIndex = str_contains($platformClass, 'PostgreSQL') || str_contains($platformClass, 'SQLite');
        foreach (array_reverse(self::TABLES, true) as $tableName => $indexName) {
            $table = $schema->getTable($tableName);
            if ($table->hasIndex($indexName)) {
                $this->addSql($usesStandaloneDropIndex
                    ? sprintf('DROP INDEX %s', $indexName)
                    : sprintf('DROP INDEX %s ON %s', $indexName, $tableName));
            }
            if ($table->hasColumn('uuid')) {
                $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN uuid', $tableName));
            }
        }
    }
}
