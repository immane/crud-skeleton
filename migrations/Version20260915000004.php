<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Core\Utils\UUID;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915000004 extends AbstractMigration
{
    /** @var list<string> */
    private const TABLES = [
        'identity_refresh_token', 'authorization_audit_log', 'authorization_role_field_grant',
        'store_membership', 'store_trade_order_cancellation', 'store_consumed_event', 'store_outbox_message',
        'trade_order_store_lifecycle', 'trade_consumed_event', 'trade_outbox_message',
        'inventory_stock', 'inventory_recipe_line', 'inventory_reservation_line', 'inventory_consumed_event', 'inventory_outbox_message',
        'settlement_consumed_event', 'settlement_outbox_message', 'wallet_voucher_comment',
    ];

    public function getDescription(): string
    {
        return 'UUID rollout step 5: backfill remaining entity UUIDs';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $tableName) {
            foreach ($this->connection->iterateAssociative(sprintf('SELECT id FROM %s WHERE uuid IS NULL', $tableName)) as $row) {
                $this->connection->executeStatement(
                    sprintf('UPDATE %s SET uuid = :uuid WHERE id = :id AND uuid IS NULL', $tableName),
                    ['uuid' => UUID::v4(), 'id' => $row['id']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $tableName) {
            $this->addSql(sprintf('UPDATE %s SET uuid = NULL', $tableName));
        }
    }
}
