<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915000002 extends AbstractMigration
{
    /** @var array<string, string> */
    private const TABLES = [
        'common_category' => 'uniq_common_category_uuid',
        'common_tag' => 'uniq_common_tag_uuid',
        'common_content' => 'uniq_common_content_uuid',
        'common_comment' => 'uniq_common_comment_uuid',
        'common_page' => 'uniq_common_page_uuid',
        'common_media' => 'uniq_common_media_uuid',
        'common_picture' => 'uniq_common_picture_uuid',
        'common_setting' => 'uniq_common_setting_uuid',
        'wallet' => 'uniq_wallet_uuid',
        'wechat_user' => 'uniq_wechat_user_uuid',
        'authorization_permission' => 'uniq_authorization_permission_uuid',
    ];

    public function getDescription(): string
    {
        return 'UUID rollout step 3: require and uniquely constrain public resource UUIDs';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            $nulls = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE uuid IS NULL', $tableName));
            $duplicates = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM (SELECT uuid FROM %s GROUP BY uuid HAVING COUNT(*) > 1) duplicate_uuids', $tableName));
            $this->abortIf($nulls > 0 || $duplicates > 0, sprintf('%s UUID backfill is incomplete or contains duplicates.', $tableName));

            $table = $schema->getTable($tableName);
            if (!$table->hasIndex($indexName)) {
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
