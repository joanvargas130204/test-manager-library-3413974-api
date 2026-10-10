<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function mostBorrowed(Request $request): JsonResponse
    {
        $from = $request->input('from');
        $to = $request->input('to');

        if (!$from || !$to) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Los parámetros "from" y "to" son obligatorios.',
                [],
                422
            );
        }

        try {
            $startDate = Carbon::parse($from, 'UTC')->startOfDay();
            $endDate = Carbon::parse($to, 'UTC')->endOfDay();
        } catch (\Exception $e) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Formato de fecha inválido. Utilice formato ISO (YYYY-MM-DD).',
                [],
                422
            );
        }

        // CA-10.2: Fin anterior al inicio: 422
        if ($endDate->lt($startDate)) {
            return ApiResponse::error(
                'INVALID_DATE_RANGE',
                'La fecha final no puede ser anterior a la fecha inicial.',
                [['field' => 'to', 'issue' => 'Fecha final previa a la inicial']],
                422
            );
        }

        // ADR-004: Agrupa por valores congelados en la línea de préstamo (book_id, book_title, category_name)
        $rows = DB::table('loan_item')
            ->join('loan', 'loan.id', '=', 'loan_item.loan_id')
            ->whereBetween('loan.loaned_at', [$startDate, $endDate])
            ->select(
                'loan_item.book_id',
                'loan_item.book_title',
                'loan_item.category_name',
                DB::raw('COUNT(loan_item.id) as times_borrowed')
            )
            ->groupBy('loan_item.book_id', 'loan_item.book_title', 'loan_item.category_name')
            ->orderByDesc('times_borrowed')
            ->orderBy('loan_item.book_title', 'asc')
            ->get();

        $formattedRows = $rows->map(function ($r) {
            return [
                'book_id' => $r->book_id,
                'book_title' => $r->book_title,
                'category_name' => $r->category_name,
                'times_borrowed' => (int) $r->times_borrowed,
            ];
        });

        return ApiResponse::success([
            'from' => $startDate->toDateString(),
            'to' => $endDate->toDateString(),
            'rows' => $formattedRows,
        ], 200);
    }
}
