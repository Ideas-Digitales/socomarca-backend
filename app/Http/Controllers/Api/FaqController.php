<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faq\DestroyRequest;
use App\Http\Requests\Faq\SearchRequest;
use App\Http\Requests\Faq\StoreRequest;
use App\Http\Requests\Faq\UpdateRequest;
use App\Http\Resources\Faq\FaqCollection;
use App\Http\Resources\Faq\FaqResource;
use App\Models\Faq;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;

#[Group('FAQ', 'Frequently asked questions. Reading and searching are public; managing them is limited to administrators.', weight: 18)]
class FaqController extends Controller
{
    /**
     * List FAQs
     *
     * Lists the FAQs, newest first.
     */
    #[QueryParameter('per_page', 'Items per page.', type: 'int', default: 20)]
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $faqs = Faq::orderBy('created_at', 'desc')->paginate($perPage);

        return new FaqCollection($faqs);
    }

    /**
     * Create a FAQ
     *
     * Allowed for `superadmin` and `admin` users with the `create-faqs` permission.
     */
    public function store(StoreRequest $request)
    {
        $faq = Faq::create($request->validated());

        return (new FaqResource($faq))->response()->setStatusCode(201);
    }

    /**
     * Get a FAQ
     */
    public function show(Faq $faq)
    {
        return new FaqResource($faq);
    }

    /**
     * Update a FAQ
     *
     * Only the fields sent are changed. Allowed for `superadmin` and `admin` users with the
     * `update-faqs` permission.
     */
    public function update(UpdateRequest $request, Faq $faq)
    {
        $faq->update($request->validated());

        return new FaqResource($faq);
    }

    /**
     * Delete a FAQ
     *
     * Allowed for `superadmin` and `admin` users with the `delete-faqs` permission.
     */
    public function destroy(DestroyRequest $request, Faq $faq)
    {
        $faq->delete();

        return response()->json([
            'message' => 'FAQ eliminada exitosamente.'
        ]);
    }


    /**
     * Search FAQs
     *
     * Lists the FAQs matching `search` and every condition in `filters`, newest first unless a filter
     * sets the order.
     */
    public function search(SearchRequest $request)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');
        $filters = $request->input('filters', []);

        $query = Faq::query();

        if ($search) {
            $query->search($search);
        }

        if (!empty($filters)) {
            $query->filter($filters);
        }

        $faqs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return new FaqCollection($faqs);
    }
}
