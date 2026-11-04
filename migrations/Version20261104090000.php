<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Core\Utils\UUID;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register the disabled-by-default global authenticated third-party payment setting.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $key = $platform->quoteIdentifier('key');
        $value = $platform->quoteIdentifier('value');
        $this->addSql("INSERT INTO common_setting (uuid, $key, $value, type, group_name, label, description, sort_order, created_at)
            SELECT ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
            WHERE NOT EXISTS (SELECT 1 FROM common_setting WHERE $key = ?)", [
            UUID::v4(), 'payment.allowThirdPartyPayment', 'false', 'boolean', 'payment',
            'Allow third-party payment', 'Allow signed-in users to pay another user\'s Trade order without changing order ownership.',
            0, 'payment.allowThirdPartyPayment',
        ]);
    }

    public function down(Schema $schema): void
    {
        // Preserve operator-owned configuration; older code ignores this key.
    }
}
