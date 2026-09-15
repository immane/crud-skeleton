<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Core\Utils\UUID;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915000001 extends AbstractMigration
{
    /** @var list<string> */
    private const TABLES = [
        'common_category',
        'common_tag',
        'common_content',
        'common_comment',
        'common_page',
        'common_media',
        'common_picture',
        'common_setting',
        'wallet',
        'wechat_user',
        'authorization_permission',
    ];

    public function getDescription(): string
    {
        return 'UUID rollout step 2: backfill public resource UUIDs';
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
