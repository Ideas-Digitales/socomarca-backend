<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreRequest;
use App\Http\Requests\Contacts\UpdateRequest;
use App\Http\Resources\Contacts\ContactCollection;
use App\Http\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\User;
use App\Services\RandomApiService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

#[Group('Contacts', 'Contacts of a Random ERP entity, used as the contact person of a checkout.', weight: 4)]
class ContactController extends Controller
{
    public function __construct(private RandomApiService $randomApi) {}

    /**
     * List contacts
     *
     * Lists the contacts stored in Compra Rápida. A secondary branch sees only its own contacts.
     * A primary branch also sees the contacts of the secondary branches of its entity. Users with
     * `read-all-contacts` get every contact. Random ERP is not queried.
     *
     * Allowed with the `read-own-contacts` or `read-all-contacts` permission.
     *
     * @response array{data: list<\App\Http\Resources\Contacts\ContactResource>}
     */
    public function index(Request $request): ContactCollection
    {
        $user = $request->user();
        $contacts = Contact::query()->orderBy('name')->orderBy('id');

        if (! $user->can('read-all-contacts')) {
            $contacts->whereIn('user_id', $user->managedBranchUserIds());
        }

        return new ContactCollection($contacts->with('user')->get());
    }

    /**
     * Create a contact
     *
     * Saves the contact in Compra Rápida and creates or updates it in Random ERP in this same
     * request, so it can be used to check out immediately. `user_id` is optional: when omitted, the
     * contact belongs to the authenticated user. A primary branch may assign it to one of its
     * secondary branches. The branch code is sent to Random ERP as `SUTRABCON`.
     *
     * Allowed with the `create-contacts` permission. Responds 201 with the created contact.
     * If Random ERP rejects the contact, nothing is saved. A branch code longer than 3 characters
     * is rejected before calling Random ERP, and nothing is saved.
     */
    #[Response(201, 'The contact was created', type: 'array{data: \App\Http\Resources\Contacts\ContactResource}')]
    #[Response(422, 'Validation error, the user has no Random ERP entity, or the branch code is longer than 3 characters')]
    #[Response(500, 'Random ERP rejected the contact; it was not saved')]
    public function store(StoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $owner = $request->owner();
        $this->rejectOversizedBranchCode($owner);

        $contact = DB::transaction(function () use ($owner, $data) {
            $contact = Contact::create([
                'user_id' => $owner->id,
                'entity_code' => trim((string) $owner->user_code),
                'rut' => $data['rut'],
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'authorized' => $data['authorized'],
                'address' => $data['address'],
                'random_synced_at' => now(),
            ]);

            $this->randomApi->updateContacts([$contact->toRandomPayload()]);

            return $contact;
        });

        return (new ContactResource($contact->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a contact
     *
     * A secondary branch can see its own contacts. A primary branch can also see the contacts of
     * its secondary branches. `read-all-contacts` sees any contact.
     */
    public function show(Contact $contact): ContactResource
    {
        return new ContactResource($contact->load('user'));
    }

    /**
     * Update a contact
     *
     * Updates the contact in Compra Rápida and in Random ERP in this same request. `PUT` requires
     * every field below; `PATCH` updates only the fields sent. The RUT cannot change.
     *
     * Allowed with the `update-contacts` permission, on the contacts the user can see, or on any
     * contact when the user also has `read-all-contacts`. A primary branch can update the contacts
     * of its secondary branches. If Random ERP rejects the change, the local contact is left as it was.
     * A branch code longer than 3 characters is rejected before calling Random ERP, and the contact
     * is left as it was.
     */
    #[Response(422, 'Validation error, or the branch code is longer than 3 characters')]
    #[Response(500, 'Random ERP rejected the contact; it was not updated')]
    public function update(UpdateRequest $request, Contact $contact): ContactResource
    {
        $data = collect($request->validated())
            ->except(['rut'])
            ->all();

        $owner = $contact->user;
        if (array_key_exists('user_id', $data) && filled($data['user_id'])) {
            $owner = User::query()->findOrFail($data['user_id']);
            $data['user_id'] = $owner->id;
            $data['entity_code'] = trim((string) $owner->user_code);
        }

        $this->rejectOversizedBranchCode($owner);

        $contact = DB::transaction(function () use ($contact, $data) {
            $contact->fill($data);
            $contact->random_synced_at = now();
            $contact->save();

            $this->randomApi->updateContacts([$contact->toRandomPayload()]);

            return $contact;
        });

        return new ContactResource($contact->load('user'));
    }

    /**
     * Random ERP stores `SUTRABCON` in 3 characters. A longer branch code cannot be sent.
     */
    private function rejectOversizedBranchCode(?User $owner): void
    {
        if (! $owner instanceof User) {
            return;
        }

        $branchCode = trim((string) $owner->branch_code);

        if (mb_strlen($branchCode) <= 3) {
            return;
        }

        Log::error('La sucursal tiene un código de más de 3 caracteres.', [
            'user_id' => $owner->id,
            'entity_code' => trim((string) $owner->user_code),
            'branch_code' => $branchCode,
        ]);

        throw ValidationException::withMessages([
            'branch_code' => 'La sucursal tiene un código de más de 3 caracteres.',
        ]);
    }
}
