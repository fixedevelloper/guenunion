<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CityController extends Controller
{

    /**
     * Récupère les villes associées au pays de l'agence liée au Staff.
     */
    public function getCityByAgencyCountry()
    {
        // 1. Récupération de l'utilisateur authentifié
        /** @var \App\Models\User $user */
        $user = Auth::user();

        // 2. Récupération du profil Staff et de son agence
        // On utilise le "Null-safe operator" (?->) de PHP 8 pour éviter les crashs si une relation est vide
        $staff  = $user->staff;
        $agency = $staff?->agency;

        // Sécurité : On valide toute la chaîne jusqu'au pays
        if (!$staff || !$agency || !$agency->country_id) {
            return response()->json([
                'success' => false,
                'message' => "Accès refusé : Impossible de déterminer la configuration géographique de votre poste de travail (Staff -> Agence)."
            ], 403);
        }

        // 3. Récupération des villes du pays de l'agence
        $cities = City::where('country_id', $agency->country_id)
            ->select('id', 'name', 'country_id')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Villes d\'exploitation chargées avec succès.',
            'data'    => $cities
        ], 200);
    }
    public function store(Request $request, $countryUuid)
    {
        $country = Country::where('uuid', $countryUuid)->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:150'
        ]);

        $city = $country->cities()->create([
            'name' => $validated['name'],
            'is_active' => true
        ]);

        return response()->json(['success' => true, 'data' => $city], 201);
    }


    public function toggleStatus($uuid)
    {
        $city = City::where('uuid', $uuid)->firstOrFail();
        $city->update(['is_active' => !$city->is_active]);

        return response()->json(['success' => true, 'message' => 'Statut de la ville mis à jour.']);
    }
}
