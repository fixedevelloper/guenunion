<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Exception;

class CountryController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Country::with('cities')->orderBy('name', 'asc')->get()
        ], 200);
    }
    public function countries()
    {
        return response()->json([
            'success' => true,
            'data' => Country::query()->orderBy('name', 'asc')->get()
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:countries,name',
            'code' => 'required|string|size:2|unique:countries,code',
            'currency_code' => 'required|string|max:5',
            'currency_symbol' => 'required|string|max:10',
            'phone_prefix' => 'required|string|max:10|unique:countries,phone_prefix',
            'can_cash_in' => 'boolean',
            'can_cash_out' => 'boolean',
            'is_active' => 'boolean'
        ]);

        $country = Country::create($validated);

        return response()->json([
            'success' => true,
            'data' => $country
        ], 201);
    }

    public function toggleStatus(Request $request, $uuid)
    {
        $request->validate([
            'field' => 'required|in:is_active,can_cash_in,can_cash_out'
        ]);

        $country = Country::where('uuid', $uuid)->firstOrFail();
        $field = $request->field;

        // Inversion dynamique du champ booléen ciblé par Next.js
        $country->update([
            $field => !$country->$field
        ]);

        return response()->json([
            'success' => true,
            'message' => "Configuration mise à jour pour le champ : {$field}"
        ], 200);
    }
}
