<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\LoanItem;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));
        $size = min(50, max(1, (int) $request->input('size', 20)));

        $query = Member::query();

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'ilike', "%{$q}%")
                    ->orWhere('document_number', 'ilike', "%{$q}%");
            });
        }

        $total = $query->count();
        $members = $query->orderBy('full_name', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $members->map(function ($m) {
            return [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'document_number' => $m->document_number,
            ];
        });

        return ApiResponse::success([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    public function show(string $id): JsonResponse
    {
        $member = Member::find($id);
        if (!$member) {
            return ApiResponse::error('NOT_FOUND', 'Socio no encontrado.', [], 404);
        }

        $activeLoans = LoanItem::whereHas('loan', fn ($q) => $q->where('member_id', $id))
            ->whereNull('returned_at')
            ->count();

        return ApiResponse::success([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => $activeLoans,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $fullName = trim((string) $request->input('full_name', ''));
        $documentNumber = trim((string) $request->input('document_number', ''));

        if ($fullName === '' || $documentNumber === '') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El nombre completo y el número de documento son obligatorios.',
                [],
                422
            );
        }

        if (Member::where('document_number', $documentNumber)->exists()) {
            return ApiResponse::error(
                'DOCUMENT_TAKEN',
                'Ya existe un socio registrado con este número de documento.',
                [['field' => 'document_number', 'issue' => 'Documento duplicado']],
                409
            );
        }

        $member = new Member();
        $member->full_name = $fullName;
        $member->document_number = $documentNumber;
        $member->save();

        return ApiResponse::success([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
        ], 201);
    }
}
