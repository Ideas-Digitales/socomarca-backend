<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Addresses\StoreRequest;
use App\Http\Requests\Addresses\UpdateRequest;
use App\Http\Resources\Addresses\AddressCollection;
use App\Models\Address;
use App\Models\Municipality;
use App\Models\Region;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Addresses', 'Manage user addresses, and the regions and municipalities (comunas) they belong to.', weight: 4)]
class AddressController extends Controller
{
    /**
     * List addresses
     *
     * Lists the authenticated user's addresses, including the branch addresses synced from Random ERP.
     * Users with the `read-all-addresses` permission get the addresses of every user. Not paginated.
     *
     * Allowed with the `read-own-addresses` or `read-all-addresses` permission.
     *
     * @response array{data: list<\App\Http\Resources\Addresses\AddressResource>}
     */
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

    /**
     * Create an address
     *
     * Creates an address for the authenticated user; its region is taken from the municipality. With
     * `is_default: true` the user's other addresses stop being the default one.
     *
     * Allowed with the `create-addresses` permission.
     */
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
        $address->region_id = Municipality::whereKey($data['municipality_id'])->value('region_id');
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

    /**
     * Show an address
     *
     * Users can see their own addresses (`read-own-addresses`), or any address with `read-all-addresses`.
     * The address is not wrapped in `data`.
     *
     * @response array{id: int, address_line1: string, address_line2: string|null, postal_code: string|null, is_default: bool, type: 'billing'|'shipping', phone: string|null, contact_name: string|null, municipality_name: string, region_name: string, alias: string|null}
     */
    public function show(Address $address)
    {
        $data = new AddressCollection([$address]);
        return response()->json($data[0]);
    }

    /**
     * Update an address
     *
     * `PUT` requires every field below; `PATCH` updates only the fields sent. With `is_default: true` the
     * authenticated user's other addresses stop being the default one, and a new `municipality_id` also
     * updates the region.
     *
     * Addresses synced from Random ERP (customer branches) cannot be updated (403). Users can update their
     * own addresses with `update-addresses`, or any address when they also have `read-all-addresses`.
     */
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
        if (array_key_exists('municipality_id', $data)) {
            $data['region_id'] = Municipality::whereKey($data['municipality_id'])->value('region_id');
        }
        $address->update($data);

        return response()->json(['message' => 'The selected address has been updated']);

    }

    /**
     * Delete an address
     *
     * Responds 200 with an empty body. Addresses synced from Random ERP (customer branches) cannot be
     * deleted (403). Users can delete their own addresses with `delete-addresses`, or any address when they
     * also have `read-all-addresses`.
     */
    public function destroy(Address $address)
    {
        $address->delete();
    }


    /**
     * List regions
     *
     * Lists every region, enabled or not, ordered by ID, each with its municipalities ordered by name.
     *
     * @response list<array{id: int, name: string, status: bool, municipalities: list<array{id: int, name: string, status: bool, region_id: int}>}>
     */
    public function regions()
    {
        return Region::with(['municipalities' => function($query) {
            $query->select('id', 'name', 'status', 'region_id')
                  ->orderBy('name');
        }])
        ->select('id', 'name', 'status')
        ->orderBy('id', 'ASC')
        ->get();
    }

    /**
     * List the municipalities of a region
     *
     * Lists the municipalities of the region, enabled or not, ordered by name. An unknown region responds 422.
     *
     * @response list<array{id: int, name: string, status: bool}>
     */
    #[PathParameter('regionId', 'The region ID.', required: true, type: 'int')]
    public function municipalities(Request $request,$regionId = null)
    {
        if ($regionId !== null) {
            $request->merge(['region_id' => $regionId]);
        }
        $validated = $request->validate([
            /**
             * Overwritten with the `regionId` path parameter.
             *
             * @ignoreParam
             */
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
     * Enable or disable municipalities
     *
     * Sets the status of the given municipalities. Their regions keep their own status.
     */
    public function updateMunicipalitiesStatus(Request $request)
    {
        $validated = $request->validate([
            'municipality_ids' => 'required|array|min:1',
            'municipality_ids.*' => 'integer|exists:municipalities,id',
            /** `true` enables the municipalities, `false` disables them. */
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
            /** @var list<array{id: int, name: string, status: bool}> */
            'municipalities' => $municipalities,
            'updated_count' => $updatedCount
        ]);
    }

    /**
     * Enable or disable a region
     *
     * Sets the status of the region and of all its municipalities.
     *
     * @param int $region The region ID.
     */
    #[Response(404, 'The region does not exist', type: 'array{message: string}')]
    public function updateRegionMunicipalitiesStatus(Request $request, $region)
    {
        $validated = $request->validate([
            /** `true` enables the region and its municipalities, `false` disables them. */
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
                /** @var int */
                'id' => $regionModel->id,
                /** @var string */
                'name' => $regionModel->name,
                /** @var bool */
                'status' => $regionModel->status,
            ],
            /** @var list<array{id: int, name: string, status: bool, region_id: int}> */
            'municipalities' => $municipalities,
            'updated_count' => $updatedCount
        ]);
    }
}
