<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Member;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user') ?? $request->user();
        $memberId = $request->input('member_id');
        $items = $request->input('items', []);

        if (!$memberId || !is_array($items)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El socio y la lista de libros son obligatorios.',
                [],
                422
            );
        }

        $itemsCount = count($items);
        if ($itemsCount < 1 || $itemsCount > 3) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Un préstamo debe contener entre 1 y 3 libros.',
                [['field' => 'items', 'issue' => 'Cantidad fuera de rango (1 a 3)']],
                422
            );
        }

        $bookIds = [];
        foreach ($items as $idx => $it) {
            if (empty($it['book_id'])) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    "Falta el identificador del libro en la posición {$idx}.",
                    [['field' => "items[{$idx}].book_id", 'issue' => 'Requerido']],
                    422
                );
            }
            $bookIds[] = $it['book_id'];
        }

        // CA-06.2: No se repite el mismo libro en un préstamo (422)
        if (count($bookIds) !== count(array_unique($bookIds))) {
            return ApiResponse::error(
                'DUPLICATE_BOOK_IN_LOAN',
                'No se puede repetir el mismo libro dentro de un mismo préstamo.',
                [['field' => 'items', 'issue' => 'Libro duplicado']],
                422
            );
        }

        $member = Member::find($memberId);
        if (!$member) {
            return ApiResponse::error('NOT_FOUND', 'El socio indicado no existe.', [], 404);
        }

        $now = Carbon::now('UTC');
        if ($request->filled('loaned_at')) {
            try {
                $parsed = Carbon::parse($request->input('loaned_at'), 'UTC');
                if ($parsed->gt($now->copy()->addMinutes(5))) {
                    return ApiResponse::error(
                        'VALIDATION_ERROR',
                        'La fecha de préstamo no puede ser futura.',
                        [['field' => 'loaned_at', 'issue' => 'Fecha futura no permitida']],
                        422
                    );
                }
                $now = $parsed;
            } catch (\Exception $e) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    'El formato de loaned_at es inválido. Use formato ISO 8601 (UTC).',
                    [['field' => 'loaned_at', 'issue' => 'Formato ISO inválido']],
                    422
                );
            }
        }

        // CA-06.5: Un socio con algún libro vencido no puede pedir otro (409)
        $hasOverdue = LoanItem::whereHas('loan', fn ($q) => $q->where('member_id', $memberId))
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->exists();

        if ($hasOverdue) {
            return ApiResponse::error(
                'MEMBER_HAS_OVERDUE',
                'El socio tiene préstamos vencidos pendientes de devolución y no puede solicitar más libros.',
                [],
                409
            );
        }

        // CA-06.4: Un socio no puede tener más de 3 libros sin devolver en total (422 con el conteo actual)
        $activeLoansCount = LoanItem::whereHas('loan', fn ($q) => $q->where('member_id', $memberId))
            ->whereNull('returned_at')
            ->count();

        if (($activeLoansCount + $itemsCount) > 3) {
            return ApiResponse::error(
                'MEMBER_LIMIT_EXCEEDED',
                "El socio ya tiene {$activeLoansCount} libro(s) sin devolver y no puede superar el límite máximo de 3.",
                [['field' => 'items', 'issue' => "Límite superado: activos actuales {$activeLoansCount}"]],
                422
            );
        }

        try {
            $createdLoan = DB::transaction(function () use ($user, $memberId, $bookIds, $now) {
                // Bloqueo optimista / pesimista sobre los libros del lote
                $books = Book::with('category')
                    ->whereIn('id', $bookIds)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($bookIds as $bId) {
                    if (!$books->has($bId)) {
                        throw new Exception("BOOK_NOT_FOUND:{$bId}");
                    }
                    $book = $books->get($bId);
                    if ($book->available_copies < 1) {
                        throw new Exception("BOOK_NOT_AVAILABLE:{$book->title}");
                    }
                }

                $loan = new Loan();
                $loan->member_id = $memberId;
                $loan->loaned_by_user_id = $user->id;
                $loan->loaned_at = $now;
                $loan->save();

                $dueAt = $now->copy()->addDays(14);

                foreach ($bookIds as $bId) {
                    $book = $books->get($bId);
                    $book->decrement('available_copies');

                    $item = new LoanItem();
                    $item->loan_id = $loan->id;
                    $item->book_id = $book->id;
                    $item->book_title = $book->title;
                    $item->isbn = trim($book->isbn);
                    $item->category_name = $book->category->name;
                    $item->due_at = $dueAt;
                    $item->returned_at = null;
                    $item->save();
                }

                return $loan;
            });
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'BOOK_NOT_FOUND:')) {
                return ApiResponse::error('NOT_FOUND', 'Uno de los libros no existe o fue dado de baja.', [], 404);
            }
            if (str_starts_with($msg, 'BOOK_NOT_AVAILABLE:')) {
                $bookTitle = substr($msg, strlen('BOOK_NOT_AVAILABLE:'));
                return ApiResponse::error(
                    'BOOK_NOT_AVAILABLE',
                    "No quedan ejemplares disponibles de «{$bookTitle}».",
                    [['field' => 'items', 'issue' => 'Sin ejemplares disponibles']],
                    409
                );
            }
            throw $e;
        }

        $createdLoan->load(['items', 'member']);

        return ApiResponse::success($this->formatLoan($createdLoan), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $from = $request->input('from');
        $to = $request->input('to');
        $status = $request->input('status');
        $memberId = $request->input('member_id');
        $page = max(1, (int) $request->input('page', 1));
        $size = min(50, max(1, (int) $request->input('size', 20)));

        $query = Loan::with(['items', 'member'])->orderBy('loaned_at', 'desc');

        if ($memberId) {
            $query->where('member_id', $memberId);
        }

        if ($from) {
            $query->where('loaned_at', '>=', Carbon::parse($from, 'UTC')->startOfDay());
        }

        if ($to) {
            $query->where('loaned_at', '<=', Carbon::parse($to, 'UTC')->endOfDay());
        }

        $loans = $query->get();

        if ($status) {
            $loans = $loans->filter(fn ($l) => $l->status === $status)->values();
        }

        $total = $loans->count();
        $paged = $loans->slice(($page - 1) * $size, $size)->values();

        $items = $paged->map(fn ($l) => $this->formatLoan($l));

        return ApiResponse::success([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show(string $id): JsonResponse
    {
        $loan = Loan::with(['items', 'member'])->find($id);
        if (!$loan) {
            return ApiResponse::error('NOT_FOUND', 'Préstamo no encontrado.', [], 404);
        }

        return ApiResponse::success($this->formatLoan($loan), 200);
    }

    public function returnItem(string $loanId, string $itemId): JsonResponse
    {
        $item = LoanItem::where('loan_id', $loanId)->where('id', $itemId)->first();

        if (!$item) {
            return ApiResponse::error('NOT_FOUND', 'Línea de préstamo no encontrada.', [], 404);
        }

        // CA-07.2: Devolver una línea ya devuelta responde 409
        if ($item->returned_at !== null) {
            return ApiResponse::error(
                'ITEM_ALREADY_RETURNED',
                'Esta línea de préstamo ya fue devuelta previamente.',
                [],
                409
            );
        }

        $now = Carbon::now('UTC');

        DB::transaction(function () use ($item, $now) {
            $item->returned_at = $now;
            $item->save();

            $book = Book::withTrashed()->find($item->book_id);
            if ($book) {
                $book->increment('available_copies');
            }
        });

        return ApiResponse::success([
            'id' => $item->id,
            'book_id' => $item->book_id,
            'book_title' => $item->book_title,
            'isbn' => trim((string) $item->isbn),
            'category_name' => $item->category_name,
            'due_at' => Carbon::parse($item->due_at)->toIso8601ZuluString(),
            'returned_at' => Carbon::parse($item->returned_at)->toIso8601ZuluString(),
        ], 200);
    }

    public function overdue(): JsonResponse
    {
        $now = Carbon::now('UTC');

        $overdueItems = LoanItem::with('loan.member')
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->orderBy('due_at', 'asc')
            ->get();

        $items = $overdueItems->map(function ($it) use ($now) {
            $due = Carbon::parse($it->due_at, 'UTC');
            $daysLate = max(1, (int) $due->diffInDays($now));

            return [
                'loan_id' => $it->loan_id,
                'item_id' => $it->id,
                'book_title' => $it->book_title,
                'member_name' => $it->loan->member->full_name ?? 'Desconocido',
                'due_at' => $due->toIso8601ZuluString(),
                'days_late' => $daysLate,
            ];
        });

        return ApiResponse::success([
            'items' => $items,
        ], 200);
    }

    private function formatLoan(Loan $loan): array
    {
        return [
            'id' => $loan->id,
            'member_id' => $loan->member_id,
            'loaned_at' => Carbon::parse($loan->loaned_at)->toIso8601ZuluString(),
            'status' => $loan->status,
            'items' => $loan->items->map(function ($it) {
                return [
                    'id' => $it->id,
                    'book_id' => $it->book_id,
                    'book_title' => $it->book_title,
                    'isbn' => trim((string) $it->isbn),
                    'category_name' => $it->category_name,
                    'due_at' => Carbon::parse($it->due_at)->toIso8601ZuluString(),
                    'returned_at' => $it->returned_at ? Carbon::parse($it->returned_at)->toIso8601ZuluString() : null,
                ];
            })->values()->all(),
        ];
    }
}
