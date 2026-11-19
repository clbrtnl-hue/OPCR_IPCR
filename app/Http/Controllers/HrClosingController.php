<?php

namespace App\Http\Controllers;

use App\Services\HrClosing;
use Illuminate\Http\Request;

class HrClosingController extends Controller
{
    public function index(Request $request, HrClosing $closing)
    {
        if ($request->user()->role !== 'hr') {
            return response()->json(['message' => 'Only HR closes rated forms.'], 403);
        }

        $yearId = $request->query('school_year_id');

        return response()->json($closing->snapshot($yearId ? (int) $yearId : null));
    }

    public function closeOffice(Request $request, HrClosing $closing, $orgUnitId)
    {
        if ($request->user()->role !== 'hr') {
            return response()->json(['message' => 'Only HR closes rated forms.'], 403);
        }

        $data = $request->validate([
            'school_year_id' => 'required|integer|exists:school_years,id',
        ]);

        if ($message = $closing->blockOffice((int) $orgUnitId, (int) $data['school_year_id'])) {
            return response()->json(['message' => $message], 422);
        }

        $closed = $closing->closeOffice((int) $orgUnitId, (int) $data['school_year_id']);

        return response()->json(['data' => 'closed', 'closed' => $closed]);
    }
}
