<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Trade store lifecycle projection and consumed event tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE trade_consumed_event (
            id BIGINT AUTO_INCREMENT NOT NULL,
            event_id VARCHAR(36) NOT NULL,
            topic VARCHAR(120) NOT NULL,
            aggregate_id VARCHAR(64) NOT NULL,
            processed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            payload_hash VARCHAR(64) NOT NULL,
            UNIQUE INDEX uniq_trade_consumed_event_id (event_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE trade_order_store_lifecycle (
            id BIGINT AUTO_INCREMENT NOT NULL,
            trade_order_uuid VARCHAR(36) NOT NULL,
            store_uuid VARCHAR(36) NOT NULL,
            store_order_uuid VARCHAR(36) DEFAULT NULL,
            acceptance_status VARCHAR(20) NOT NULL,
            fulfillment_status VARCHAR(20) NOT NULL,
            verification_status VARCHAR(20) NOT NULL,
            rejection_code VARCHAR(50) DEFAULT NULL,
            rejection_reason LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_trade_order_store_lifecycle_uuid (trade_order_uuid),
            INDEX idx_trade_order_store_lifecycle_store (store_uuid),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trade_order_store_lifecycle');
        $this->addSql('DROP TABLE trade_consumed_event');
    }
}
