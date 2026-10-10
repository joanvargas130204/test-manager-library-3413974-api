<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function getAdminToken(): string
    {
        $admin = new User();
        $admin->username = 'admin';
        $admin->password = 'AdminPassword123!';
        $admin->role = 'admin';
        $admin->save();

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'AdminPassword123!',
        ]);

        return $res->json('access_token');
    }

    public function test_health_check_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');
        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    public function test_login_validation_and_failures(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'INVALID_CREDENTIALS',
            ]);
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $token = $this->getAdminToken();
        $this->assertNotEmpty($token);
    }

    public function test_protected_routes_require_token(): void
    {
        $response = $this->getJson('/api/v1/books');
        $response->assertStatus(401)
            ->assertJson([
                'code' => 'INVALID_CREDENTIALS',
            ]);
    }

    public function test_categories_are_seeded(): void
    {
        $token = $this->getAdminToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/categories');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json());
    }

    public function test_create_and_duplicate_isbn_book(): void
    {
        $token = $this->getAdminToken();
        $cat = Category::first();

        $createRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/books', [
                'title' => 'Rayuela',
                'author' => 'Julio Cortázar',
                'isbn' => '9788420437484',
                'category_id' => $cat->id,
                'total_copies' => 3,
            ]);

        $createRes->assertStatus(201)
            ->assertJson([
                'title' => 'Rayuela',
                'available_copies' => 3,
            ]);

        // Duplicado -> 409
        $dupRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/books', [
                'title' => 'Rayuela Segunda Edición',
                'author' => 'Julio Cortázar',
                'isbn' => '9788420437484',
                'category_id' => $cat->id,
                'total_copies' => 2,
            ]);

        $dupRes->assertStatus(409)
            ->assertJson(['code' => 'ISBN_TAKEN']);
    }

    public function test_member_registration_and_duplicate_document(): void
    {
        $token = $this->getAdminToken();

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/members', [
                'full_name' => 'Carlos Pérez',
                'document_number' => '1020304050',
            ]);

        $res->assertStatus(201)
            ->assertJson(['full_name' => 'Carlos Pérez']);

        // Duplicado
        $dup = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/members', [
                'full_name' => 'Carlos Pérez Dos',
                'document_number' => '1020304050',
            ]);

        $dup->assertStatus(409)
            ->assertJson(['code' => 'DOCUMENT_TAKEN']);
    }

    public function test_loan_and_return_cycle_and_business_rules(): void
    {
        $token = $this->getAdminToken();
        $cat = Category::first();

        // 1. Crear socio
        $member = Member::create([
            'full_name' => 'Laura Morales',
            'document_number' => '9988776655',
        ]);

        // 2. Crear libros con 1 ejemplar disponible
        $book1 = Book::create([
            'title' => 'Libro Uno',
            'author' => 'Autor Uno',
            'isbn' => '1111111111111',
            'category_id' => $cat->id,
            'total_copies' => 1,
            'available_copies' => 1,
        ]);

        $book2 = Book::create([
            'title' => 'Libro Dos',
            'author' => 'Autor Dos',
            'isbn' => '2222222222222',
            'category_id' => $cat->id,
            'total_copies' => 1,
            'available_copies' => 1,
        ]);

        // Regla: No repetir libro en préstamo -> 422
        $dupLoan = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [
                    ['book_id' => $book1->id],
                    ['book_id' => $book1->id],
                ],
            ]);
        $dupLoan->assertStatus(422)
            ->assertJson(['code' => 'DUPLICATE_BOOK_IN_LOAN']);

        // Prestar libro 1
        $loanRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [['book_id' => $book1->id]],
            ]);
        $loanRes->assertStatus(201);

        // Verificar que libro 1 quedó con 0 disponibles
        $this->assertEquals(0, $book1->fresh()->available_copies);

        // Prestar libro 1 nuevamente sin stock -> 409 BOOK_NOT_AVAILABLE
        $noStock = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [['book_id' => $book1->id]],
            ]);
        $noStock->assertStatus(409)
            ->assertJson(['code' => 'BOOK_NOT_AVAILABLE']);

        // Devolución de la línea
        $loanId = $loanRes->json('id');
        $itemId = $loanRes->json('items.0.id');

        $returnRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/loans/{$loanId}/items/{$itemId}/return");
        $returnRes->assertStatus(200);

        // Disponibles deben ser 1 nuevamente
        $this->assertEquals(1, $book1->fresh()->available_copies);

        // Devolver nuevamente -> 409 ITEM_ALREADY_RETURNED
        $dupReturn = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/loans/{$loanId}/items/{$itemId}/return");
        $dupReturn->assertStatus(409)
            ->assertJson(['code' => 'ITEM_ALREADY_RETURNED']);
    }

    public function test_admin_cannot_be_granted_via_api(): void
    {
        $token = $this->getAdminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/users', [
                'username' => 'hackadmin',
                'password' => 'password123',
                'role' => 'admin',
            ]);

        $response->assertStatus(422)
            ->assertJson(['code' => 'VALIDATION_ERROR']);
    }

    public function test_member_cannot_exceed_3_active_books_limit(): void
    {
        $token = $this->getAdminToken();
        $cat = Category::first();
        $member = Member::create(['full_name' => 'Marcos Lima', 'document_number' => '5544332211']);

        $b1 = Book::create(['title' => 'B1', 'author' => 'Autor Test', 'isbn' => '9780000000001', 'category_id' => $cat->id, 'total_copies' => 5, 'available_copies' => 5]);
        $b2 = Book::create(['title' => 'B2', 'author' => 'Autor Test', 'isbn' => '9780000000002', 'category_id' => $cat->id, 'total_copies' => 5, 'available_copies' => 5]);
        $b3 = Book::create(['title' => 'B3', 'author' => 'Autor Test', 'isbn' => '9780000000003', 'category_id' => $cat->id, 'total_copies' => 5, 'available_copies' => 5]);
        $b4 = Book::create(['title' => 'B4', 'author' => 'Autor Test', 'isbn' => '9780000000004', 'category_id' => $cat->id, 'total_copies' => 5, 'available_copies' => 5]);

        // Prestar 2 libros
        $res1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [['book_id' => $b1->id], ['book_id' => $b2->id]],
            ]);
        $res1->assertStatus(201);

        // Intentar pedir 2 libros adicionales (2 activos + 2 nuevos = 4 > 3) -> 422
        $res2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [['book_id' => $b3->id], ['book_id' => $b4->id]],
            ]);
        $res2->assertStatus(422)
            ->assertJson(['code' => 'MEMBER_LIMIT_EXCEEDED']);
    }

    public function test_member_with_overdue_loan_is_blocked_from_new_loans(): void
    {
        $token = $this->getAdminToken();
        $cat = Category::first();
        $member = Member::create(['full_name' => 'Socio Con Mora', 'document_number' => '7788990011']);

        $b1 = Book::create(['title' => 'Mora B1', 'author' => 'Autor Test', 'isbn' => '9781000000001', 'category_id' => $cat->id, 'total_copies' => 2, 'available_copies' => 2]);
        $b2 = Book::create(['title' => 'Mora B2', 'author' => 'Autor Test', 'isbn' => '9781000000002', 'category_id' => $cat->id, 'total_copies' => 2, 'available_copies' => 2]);

        // Crear préstamo histórico con loaned_at hace 20 días (vencido hace 6 días)
        $historicalDate = now('UTC')->subDays(20)->toIso8601String();
        $loanRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'loaned_at' => $historicalDate,
                'items' => [['book_id' => $b1->id]],
            ]);
        $loanRes->assertStatus(201);

        // Intentar nuevo préstamo teniendo el anterior vencido -> 409 MEMBER_HAS_OVERDUE
        $blockedRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [['book_id' => $b2->id]],
            ]);
        $blockedRes->assertStatus(409)
            ->assertJson(['code' => 'MEMBER_HAS_OVERDUE']);
    }

    public function test_cannot_delete_book_with_open_loans(): void
    {
        $token = $this->getAdminToken();
        $cat = Category::first();
        $member = Member::create(['full_name' => 'Socio Prestamo', 'document_number' => '6655443322']);
        $book = Book::create(['title' => 'Libro Protegido', 'author' => 'Autor Test', 'isbn' => '9782000000001', 'category_id' => $cat->id, 'total_copies' => 2, 'available_copies' => 2]);

        // Prestar
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [['book_id' => $book->id]],
            ])->assertStatus(201);

        // Intentar dar de baja el libro con préstamo abierto -> 409 BOOK_HAS_OPEN_LOANS
        $delRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/books/{$book->id}");
        $delRes->assertStatus(409)
            ->assertJson(['code' => 'BOOK_HAS_OPEN_LOANS']);
    }

    public function test_most_borrowed_report_preserves_frozen_title_adr004(): void
    {
        $token = $this->getAdminToken();
        $cat = Category::first();
        $member = Member::create(['full_name' => 'Lector Frecuente', 'document_number' => '4433221100']);
        $book = Book::create(['title' => 'Titulo Original 1984', 'author' => 'George Orwell', 'isbn' => '9783000000001', 'category_id' => $cat->id, 'total_copies' => 2, 'available_copies' => 2]);

        // Prestar bajo el título original
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [['book_id' => $book->id]],
            ])->assertStatus(201);

        // Actualizar/renombrar el libro en el catálogo
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/books/{$book->id}", [
                'title' => 'Titulo Nuevo y Modificado',
                'author' => 'George Orwell',
                'category_id' => $cat->id,
                'total_copies' => 2,
            ])->assertStatus(200);

        // Consultar el reporte histórico: debe conservar 'Titulo Original 1984' según ADR-004
        $reportRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/reports/most-borrowed?from=' . now('UTC')->subDay()->toDateString() . '&to=' . now('UTC')->addDay()->toDateString());

        $reportRes->assertStatus(200);
        $found = collect($reportRes->json('rows'))->firstWhere('book_id', $book->id);
        $this->assertNotNull($found);
        $this->assertEquals('Titulo Original 1984', $found['book_title']);
    }

    public function test_loaned_at_validation_rejects_invalid_or_future_dates(): void
    {
        $token = $this->getAdminToken();
        $cat = Category::first();
        $member = Member::create(['full_name' => 'Test Validacion', 'document_number' => '3322110099']);
        $book = Book::create(['title' => 'Libro Val', 'author' => 'Autor Test', 'isbn' => '9784000000001', 'category_id' => $cat->id, 'total_copies' => 2, 'available_copies' => 2]);

        // Fecha inválida -> 422
        $resInvalid = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'loaned_at' => 'fecha-no-valida',
                'items' => [['book_id' => $book->id]],
            ]);
        $resInvalid->assertStatus(422)->assertJson(['code' => 'VALIDATION_ERROR']);

        // Fecha futura -> 422
        $resFuture = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'loaned_at' => now('UTC')->addDays(5)->toIso8601String(),
                'items' => [['book_id' => $book->id]],
            ]);
        $resFuture->assertStatus(422)->assertJson(['code' => 'VALIDATION_ERROR']);
    }
}
