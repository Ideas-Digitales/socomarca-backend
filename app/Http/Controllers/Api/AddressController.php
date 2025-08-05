<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Addresses\StoreRequest;
use App\Http\Requests\Addresses\UpdateRequest;
use App\Http\Resources\Addresses\AddressCollection;
use App\Models\Address;
use App\Models\Municipality;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function index(Request $request)
    {

        $user = $request->user();
        $addresses = null;

        if ($user->can('read-all-addresses')) {
            $addresses = Address::all();
        } else {
            $addresses = Address::where('user_id', $user->id)->get();
        }

        $data = new AddressCollection($addresses);

        return $data;
    }

    public function store(StoreRequest $storeRequest)
    {
        $user = $storeRequest->user();
        $data = $storeRequest->validated();
        $data['is_default'] === true &&
            DB::table('addresses')
                ->where('user_id', $user->id)
                ->update(['is_default' => false]);

        $address = new Address;

        $address->address_line1 = $data['address_line1'];
        $address->address_line2 = $data['address_line2'] ?? null;
        $address->postal_code = $data['postal_code'] ?? null;
        $address->is_default = $data['is_default'];
        $address->type = $data['type'];
        $address->phone = $data['phone'];
        $address->contact_name = $data['contact_name'];
        $address->user_id = $user->id;
        $address->municipality_id = $data['municipality_id'];
        $address->alias = $data['alias'];

        $address->save();


        $address->load('municipality.region');

        return response()->json([
            'id' => $address->id,
            'address_line1' => $address->address_line1,
            'address_line2' => $address->address_line2,
            'postal_code' => $address->postal_code,
            'is_default' => $address->is_default,
            'type' => $address->type,
            'phone' => $address->phone,
            'contact_name' => $address->contact_name,
            'alias' => $address->alias,
            'municipality' => [
                'id' => $address->municipality->id,
                'name' => $address->municipality->name,
                'region' => [
                    'id' => $address->municipality->region->id,
                    'name' => $address->municipality->region->name,
                ]
            ],
            'created_at' => $address->created_at,
            'updated_at' => $address->updated_at,
        ], 201);
    }

    public function show(Address $address)
    {
        $data = new AddressCollection([$address]);
        return response()->json($data[0]);
    }

    public function update(UpdateRequest $updateRequest, Address $address)
    {
        $user = $updateRequest->user();
        $data = $updateRequest->validated();

        //$data['is_default'] === true &&
        if (array_key_exists('is_default', $data) && $data['is_default'] === true) {
            DB::table('addresses')
                ->where('user_id',$user->id)
                ->update(['is_default' => false]);
        }
        $data['user_id'] = $user->id;
        $address->update($data);

        return response()->json(['message' => 'The selected address has been updated']);

    }

    public function destroy(Address $address)
    {
        $address->delete();
    }


    /**
     * Get all regions
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function regions()
    {

        //return Region::select('id', 'name','status')->orderBy('id','ASC')->get();
        return Region::with(['municipalities' => function($query) {
            $query->select('id', 'name', 'status', 'region_id')
                  ->orderBy('name');
        }])
        ->select('id', 'name', 'status')
        ->orderBy('id', 'ASC')
        ->get();
    }

    /**
     * Get municipalities by region ID
     *
     * @param \Illuminate\Http\Request $request
     * @param int|null $regionId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function municipalities(Request $request,$regionId = null)
    {
        if ($regionId !== null) {
            $request->merge(['region_id' => $regionId]);
        }
        $validated = $request->validate([
            'region_id' => 'nullable|integer|exists:regions,id',
        ],
        [
            'region_id.exists' => 'error',
            'region_id.integer' => 'error',
        ]);

        $query = Municipality::select('id', 'name', 'status');
        if ($regionId) {
            $query->where('region_id', $regionId);
        }
        return $query->orderBy('name')->get();
    }

    /**
     * Update multiple municipalities status
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateMunicipalitiesStatus(Request $request)
    {
        $validated = $request->validate([
            'municipality_ids' => 'required|array|min:1',
            'municipality_ids.*' => 'integer|exists:municipalities,id',
            'status' => 'required|boolean',
        ]);

        $municipalityIds = $validated['municipality_ids'];
        $status = $validated['status'];

        // Actualizar todas las comunas con el nuevo status
        $updatedCount = Municipality::whereIn('id', $municipalityIds)
            ->update(['status' => $status]);

        // Obtener las comunas actualizadas para la respuesta
        $municipalities = Municipality::whereIn('id', $municipalityIds)
            ->select('id', 'name', 'status')
            ->get();

        return response()->json([
            'message' => "Successfully updated {$updatedCount} municipalities",
            'municipalities' => $municipalities,
            'updated_count' => $updatedCount
        ]);
    }

    /**
     * Update all municipalities status for a specific region
     *
     * @param \Illuminate\Http\Request $request
     * @param int $region
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateRegionMunicipalitiesStatus(Request $request, $region)
    {
        $validated = $request->validate([
            'status' => 'required|boolean',
        ]);

        // Verificar que la región existe
        $regionModel = Region::findOrFail($region);
        $status = $validated['status'];

        // Actualizar el status de la región
        $regionModel->update(['status' => $status]);

        // Actualizar todas las comunas de la región
        $updatedCount = Municipality::where('region_id', $region)
            ->update(['status' => $status]);

        // Obtener las comunas actualizadas para la respuesta
        $municipalities = Municipality::where('region_id', $region)
            ->select('id', 'name', 'status', 'region_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => "Successfully updated {$updatedCount} municipalities in region '{$regionModel->name}'",
            'region' => [
                'id' => $regionModel->id,
                'name' => $regionModel->name,
                'status' => $regionModel->status,
            ],
            'municipalities' => $municipalities,
            'updated_count' => $updatedCount
        ]);
    }
}
