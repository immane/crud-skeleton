<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915000005 extends AbstractMigration
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
        return 'UUID rollout step 6: require and uniquely constrain remaining entity UUIDs';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            $nulls = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE uuid IS NULL', $tableName));
            $duplicates = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM (SELECT uuid FROM %s GROUP BY uuid HAVING COUNT(*) > 1) duplicate_uuids', $tableName));
            $this->abortIf($nulls > 0 || $duplicates > 0, sprintf('%s UUID backfill is incomplete or contains duplicates.', $tableName));

            if (!$schema->getTable($tableName)->hasIndex($indexName)) {
                $this->addSql(sprintf('CREATE UNIQUE INDEX %s ON %s (uuid)', $indexName, $tableName));
            }
        }

        $platformClass = $this->connection->getDatabasePlatform()::class;
        foreach (array_keys(self::TABLES) as $tableName) {
            if (str_contains($platformClass, 'MySQL')) {
                $this->addSql(sprintf('ALTER TABLE %s MODIFY uuid VARCHAR(36) NOT NULL', $tableName));
            } elseif (str_contains($platformClass, 'PostgreSQL')) {
                $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN uuid SET NOT NULL', $tableName));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $platformClass = $this->connection->getDatabasePlatform()::class;
        $isPostgreSql = str_contains($platformClass, 'PostgreSQL');
        $usesStandaloneDropIndex = $isPostgreSql || str_contains($platformClass, 'SQLite');
        foreach (array_reverse(self::TABLES, true) as $tableName => $indexName) {
            if (str_contains($platformClass, 'MySQL')) {
                $this->addSql(sprintf('ALTER TABLE %s MODIFY uuid VARCHAR(36) DEFAULT NULL', $tableName));
            } elseif ($isPostgreSql) {
                $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN uuid DROP NOT NULL', $tableName));
            }

            if ($schema->getTable($tableName)->hasIndex($indexName)) {
                $this->addSql($usesStandaloneDropIndex
                    ? sprintf('DROP INDEX %s', $indexName)
                    : sprintf('DROP INDEX %s ON %s', $indexName, $tableName));
            }
        }
    }
}
