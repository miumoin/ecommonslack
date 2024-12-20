<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241215045819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CEED9578989D9B62 ON blocks (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_61ADD8B2C1D5962E ON systems (subdomain)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_61ADD8B2A7A91E0B ON systems (domain)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_1483A5E9E7927C74 ON users');
        $this->addSql('DROP INDEX UNIQ_CEED9578989D9B62 ON blocks');
        $this->addSql('DROP INDEX UNIQ_61ADD8B2C1D5962E ON systems');
        $this->addSql('DROP INDEX UNIQ_61ADD8B2A7A91E0B ON systems');
    }
}
