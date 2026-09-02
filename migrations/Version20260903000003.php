<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove StoreOrder thorough bypass: drop store_order, store_outbox, store_consumed, store_trade_order_cancellation, trade_outbox';
    }

    public function up(Schema $schema): void
    {
        // Drop FK first for store_order -> store
        if ($schema->hasTable('store_order')) {
            $table = $schema->getTable('store_order');
            if ($table->hasForeignKey('FK_STORE_ORDER_STORE')) {
                $this->addSql('ALTER TABLE store_order DROP FOREIGN KEY FK_STORE_ORDER_STORE');
            } elseif ($table->hasForeignKey('FK_772AAAD6B092A811')) {
                $this->addSql('ALTER TABLE store_order DROP FOREIGN KEY FK_772AAAD6B092A811');
            }
            // Drop indexes before table drop is not required, DROP TABLE handles it, but for explicit order:
            $this->addSql('DROP TABLE store_order');
        }

        if ($schema->hasTable('store_outbox_message')) {
            $this->addSql('DROP TABLE store_outbox_message');
        }

        if ($schema->hasTable('store_consumed_event')) {
            $this->addSql('DROP TABLE store_consumed_event');
        }

        if ($schema->hasTable('store_trade_order_cancellation')) {
            $this->addSql('DROP TABLE store_trade_order_cancellation');
        }

        if ($schema->hasTable('trade_outbox_message')) {
            $this->addSql('DROP TABLE trade_outbox_message');
        }
    }

    public function down(Schema $schema): void
    {
        // Recreate is not supported — requires full Store domain schema. Restore from Version20260725010000/020000/26000000.
        $this->abortIf(true, 'StoreOrder bypass migration cannot be reverted without restoring Store outbox schema.');
    }
}
