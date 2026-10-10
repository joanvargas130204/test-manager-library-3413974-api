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
}
