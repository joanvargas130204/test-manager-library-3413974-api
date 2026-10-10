<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        $isPgsql = $driver === 'pgsql';

        if ($isPgsql) {
            DB::statement('CREATE SCHEMA IF NOT EXISTS library;');
            $p = 'library.';
        } else {
            $p = '';
        }

        DB::statement("
            CREATE TABLE {$p}category (
                id VARCHAR(36) PRIMARY KEY,
                name VARCHAR(120) NOT NULL UNIQUE
            );
        ");

        DB::statement("
            INSERT INTO {$p}category (id, name) VALUES
            ('11111111-1111-4111-8111-111111111111', 'Novela'),
            ('22222222-2222-4222-8222-222222222222', 'Ciencia'),
            ('33333333-3333-4333-8333-333333333333', 'Tecnología'),
            ('44444444-4444-4444-8444-444444444444', 'Historia'),
            ('55555555-5555-4555-8555-555555555555', 'Infantil');
        ");

        DB::statement("
            CREATE TABLE {$p}\"user\" (
                id VARCHAR(36) PRIMARY KEY,
                username VARCHAR(120) NOT NULL UNIQUE,
                password_hash VARCHAR(512) NOT NULL,
                role VARCHAR(40) NOT NULL,
                CONSTRAINT ck_user_role CHECK (role IN ('admin', 'librarian'))
            );
        ");

        if ($isPgsql) {
            DB::statement("
                CREATE TABLE library.book (
                    id UUID PRIMARY KEY,
                    title VARCHAR(250) NOT NULL,
                    author VARCHAR(200) NOT NULL,
                    isbn CHAR(13) NOT NULL,
                    category_id UUID NOT NULL REFERENCES library.category(id) ON DELETE RESTRICT,
                    total_copies INTEGER NOT NULL,
                    available_copies INTEGER NOT NULL,
                    cover_key VARCHAR(512),
                    deleted_at TIMESTAMPTZ,
                    CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1),
                    CONSTRAINT ck_book_available_range CHECK (available_copies >= 0 AND available_copies <= total_copies)
                );
            ");
            DB::statement('CREATE UNIQUE INDEX idx_book_isbn_active ON library.book (isbn) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_book_category_title ON library.book (category_id, title) WHERE deleted_at IS NULL;');
        } else {
            DB::statement("
                CREATE TABLE book (
                    id VARCHAR(36) PRIMARY KEY,
                    title VARCHAR(250) NOT NULL,
                    author VARCHAR(200) NOT NULL,
                    isbn CHAR(13) NOT NULL,
                    category_id VARCHAR(36) NOT NULL REFERENCES category(id) ON DELETE RESTRICT,
                    total_copies INTEGER NOT NULL,
                    available_copies INTEGER NOT NULL,
                    cover_key VARCHAR(512),
                    deleted_at DATETIME,
                    CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1),
                    CONSTRAINT ck_book_available_range CHECK (available_copies >= 0 AND available_copies <= total_copies)
                );
            ");
        }

        DB::statement("
            CREATE TABLE {$p}member (
                id VARCHAR(36) PRIMARY KEY,
                full_name VARCHAR(200) NOT NULL,
                document_number VARCHAR(30) NOT NULL UNIQUE
            );
        ");

        if ($isPgsql) {
            DB::statement("
                CREATE TABLE library.loan (
                    id UUID PRIMARY KEY,
                    member_id UUID NOT NULL REFERENCES library.member(id) ON DELETE RESTRICT,
                    loaned_by_user_id UUID NOT NULL REFERENCES library.\"user\"(id) ON DELETE RESTRICT,
                    loaned_at TIMESTAMPTZ NOT NULL
                );
            ");
            DB::statement('CREATE INDEX idx_loan_loaned_at ON library.loan (loaned_at);');

            DB::statement("
                CREATE TABLE library.loan_item (
                    id UUID PRIMARY KEY,
                    loan_id UUID NOT NULL REFERENCES library.loan(id) ON DELETE CASCADE,
                    book_id UUID NOT NULL REFERENCES library.book(id) ON DELETE RESTRICT,
                    book_title VARCHAR(250) NOT NULL,
                    isbn CHAR(13) NOT NULL,
                    category_name VARCHAR(120) NOT NULL,
                    due_at TIMESTAMPTZ NOT NULL,
                    returned_at TIMESTAMPTZ,
                    CONSTRAINT uq_loan_book UNIQUE (loan_id, book_id)
                );
            ");
            DB::statement('CREATE INDEX idx_loan_item_book_id ON library.loan_item (book_id);');
            DB::statement('CREATE INDEX idx_loan_item_due_unreturned ON library.loan_item (due_at) WHERE returned_at IS NULL;');
        } else {
            DB::statement("
                CREATE TABLE loan (
                    id VARCHAR(36) PRIMARY KEY,
                    member_id VARCHAR(36) NOT NULL REFERENCES member(id) ON DELETE RESTRICT,
                    loaned_by_user_id VARCHAR(36) NOT NULL REFERENCES \"user\"(id) ON DELETE RESTRICT,
                    loaned_at DATETIME NOT NULL
                );
            ");

            DB::statement("
                CREATE TABLE loan_item (
                    id VARCHAR(36) PRIMARY KEY,
                    loan_id VARCHAR(36) NOT NULL REFERENCES loan(id) ON DELETE CASCADE,
                    book_id VARCHAR(36) NOT NULL REFERENCES book(id) ON DELETE RESTRICT,
                    book_title VARCHAR(250) NOT NULL,
                    isbn CHAR(13) NOT NULL,
                    category_name VARCHAR(120) NOT NULL,
                    due_at DATETIME NOT NULL,
                    returned_at DATETIME,
                    CONSTRAINT uq_loan_book UNIQUE (loan_id, book_id)
                );
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        $isPgsql = $driver === 'pgsql';
        $p = $isPgsql ? 'library.' : '';

        DB::statement("DROP TABLE IF EXISTS {$p}loan_item CASCADE;");
        DB::statement("DROP TABLE IF EXISTS {$p}loan CASCADE;");
        DB::statement("DROP TABLE IF EXISTS {$p}member CASCADE;");
        DB::statement("DROP TABLE IF EXISTS {$p}book CASCADE;");
        DB::statement("DROP TABLE IF EXISTS {$p}\"user\" CASCADE;");
        DB::statement("DROP TABLE IF EXISTS {$p}category CASCADE;");

        if ($isPgsql) {
            DB::statement('DROP SCHEMA IF EXISTS library CASCADE;');
        }
    }
};
