<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915000000 extends AbstractMigration
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
        return 'UUID rollout step 1: add nullable UUID columns to public resources';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $tableName => $_) {
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('uuid')) {
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
