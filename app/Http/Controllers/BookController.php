<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Book;
use App\Models\Category;
use App\Models\LoanItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $categoryId = $request->input('category_id');
        $page = max(1, (int) $request->input('page', 1));
        $size = min(50, max(1, (int) $request->input('size', 20)));

        $query = Book::with('category')->whereNull('deleted_at');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'ilike', "%{$q}%")
                    ->orWhere('author', 'ilike', "%{$q}%");
            });
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $total = $query->count();
        $books = $query->orderBy('title', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $books->map(fn ($book) => $this->formatBook($book));

        return ApiResponse::success([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show(string $id): JsonResponse
    {
        $book = Book::with('category')->whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe o fue dado de baja.', [], 404);
        }

        return ApiResponse::success($this->formatBook($book), 200);
    }

    public function store(Request $request): JsonResponse
    {
        $title = trim((string) $request->input('title', ''));
        $author = trim((string) $request->input('author', ''));
        $isbn = trim((string) $request->input('isbn', ''));
        $categoryId = $request->input('category_id');
        $totalCopies = (int) $request->input('total_copies', 0);

        if ($title === '' || $author === '' || $isbn === '' || !$categoryId || $totalCopies < 1) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Todos los campos son obligatorios y los ejemplares deben ser al menos 1.',
                [],
                422
            );
        }

        if (strlen($isbn) !== 13 || !ctype_digit($isbn)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El ISBN debe contener exactamente 13 dígitos numéricos.',
                [['field' => 'isbn', 'issue' => 'Longitud o formato inválido']],
                422
            );
        }

        if (!Category::where('id', $categoryId)->exists()) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'La categoría especificada no existe.',
                [['field' => 'category_id', 'issue' => 'Categoría inexistente']],
                422
            );
        }

        // ISBN único entre libros activos
        if (Book::where('isbn', $isbn)->whereNull('deleted_at')->exists()) {
            return ApiResponse::error(
                'ISBN_TAKEN',
                'Ya existe un libro activo registrado con este ISBN.',
                [['field' => 'isbn', 'issue' => 'ISBN duplicado']],
                409
            );
        }

        $book = new Book();
        $book->title = $title;
        $book->author = $author;
        $book->isbn = $isbn;
        $book->category_id = $categoryId;
        $book->total_copies = $totalCopies;
        $book->available_copies = $totalCopies;
        $book->cover_key = null;
        $book->save();

        $book->load('category');

        return ApiResponse::success($this->formatBook($book), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $book = Book::with('category')->whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'Libro no encontrado.', [], 404);
        }

        $title = trim((string) $request->input('title', $book->title));
        $author = trim((string) $request->input('author', $book->author));
        $categoryId = $request->input('category_id', $book->category_id);
        $newTotal = $request->has('total_copies') ? (int) $request->input('total_copies') : $book->total_copies;

        if ($newTotal < 1) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El total de ejemplares debe ser mayor o igual a 1.',
                [['field' => 'total_copies', 'issue' => 'Debe ser >= 1']],
                422
            );
        }

        $diff = $newTotal - $book->total_copies;
        $newAvailable = $book->available_copies + $diff;

        if ($newAvailable < 0) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'No es posible reducir el total de ejemplares: los disponibles quedarían en negativo.',
                [['field' => 'total_copies', 'issue' => 'Disponibles < 0']],
                422
            );
        }

        $book->title = $title;
        $book->author = $author;
        $book->category_id = $categoryId;
        $book->total_copies = $newTotal;
        $book->available_copies = $newAvailable;
        $book->save();

        $book->load('category');

        return ApiResponse::success($this->formatBook($book), 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $book = Book::whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'Libro no encontrado.', [], 404);
        }

        // CA-03.5: Un libro con préstamos abiertos no se da de baja
        $hasOpenLoans = LoanItem::where('book_id', $id)
            ->whereNull('returned_at')
            ->exists();

        if ($hasOpenLoans) {
            return ApiResponse::error(
                'BOOK_HAS_OPEN_LOANS',
                'No se puede dar de baja un libro con préstamos activos pendientes de devolución.',
                [],
                409
            );
        }

        $book->delete();

        return ApiResponse::success(null, 204);
    }

    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $book = Book::with('category')->whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'Libro no encontrado.', [], 404);
        }

        if (!$request->hasFile('file')) {
            return ApiResponse::error('VALIDATION_ERROR', 'Archivo de portada no enviado.', [], 422);
        }

        $file = $request->file('file');
        $mime = $file->getMimeType();

        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return ApiResponse::error('VALIDATION_ERROR', 'Formato de imagen inválido. Solo se admiten JPG o PNG.', [], 422);
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return ApiResponse::error('VALIDATION_ERROR', 'El tamaño de la imagen no puede superar 2 MB.', [], 422);
        }

        $key = (string) Str::uuid();
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = "{$key}.{$extension}";

        $file->storeAs('covers', $filename, 'public');

        $book->cover_key = $filename;
        $book->save();

        return ApiResponse::success($this->formatBook($book), 200);
    }

    public function getCover(string $id): BinaryFileResponse|JsonResponse
    {
        $book = Book::whereNull('deleted_at')->find($id);
        if (!$book || empty($book->cover_key)) {
            return ApiResponse::error('NOT_FOUND', 'Portada no disponible.', [], 404);
        }

        $path = storage_path("app/public/covers/{$book->cover_key}");
        if (!file_exists($path)) {
            return ApiResponse::error('NOT_FOUND', 'Archivo físico de portada no encontrado.', [], 404);
        }

        return response()->file($path);
    }

    public function deleteCover(string $id): JsonResponse
    {
        $book = Book::with('category')->whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'Libro no encontrado.', [], 404);
        }

        if ($book->cover_key) {
            $path = storage_path("app/public/covers/{$book->cover_key}");
            $book->cover_key = null;
            $book->save();

            if (file_exists($path)) {
                @unlink($path);
            }
        }

        return ApiResponse::success($this->formatBook($book), 200);
    }

    private function formatBook(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => trim((string) $book->isbn),
            'category' => [
                'id' => $book->category->id,
                'name' => $book->category->name,
            ],
            'total_copies' => (int) $book->total_copies,
            'available_copies' => (int) $book->available_copies,
            'cover_key' => $book->cover_key,
        ];
    }
}
