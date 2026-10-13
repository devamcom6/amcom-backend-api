<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FaqProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FaqRequest;
use App\Http\Resources\FaqResource;
use App\Http\Resources\TestimonialResource;
use App\Models\Faq;
use App\Models\Testimonial;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §15.8 — content.manage */
class ContentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function faqs(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Faq::query()
            ->when($list->filter('product', FaqProduct::values()), fn ($q, $v) => $q->whereIn('product', $v))
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w->where('question', 'like', "%{$term}%")->orWhere('answer', 'like', "%{$term}%")))
            ->orderBy('product')
            ->orderBy('position')
            ->orderBy('id');

        return ApiResponse::paginated($list->paginate($query, 25), FaqResource::class);
    }

    public function storeFaq(FaqRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['position'] ??= (int) Faq::query()->where('product', $data['product'])->max('position') + 1;
        $data['is_published'] ??= true;

        $faq = Faq::create($data);
        $this->audit->log('faq.created', $faq, null, $data, 'created FAQ', subjectLabel: mb_substr($faq->question, 0, 120));

        return ApiResponse::created(new FaqResource($faq), 'FAQ created.');
    }

    public function updateFaq(FaqRequest $request, int $id): JsonResponse
    {
        $faq = Faq::query()->findOrFail($id);
        $old = $faq->only(['product', 'question', 'answer', 'is_published', 'position']);

        $faq->fill($request->validated())->save();
        $this->audit->log('faq.updated', $faq, $old, $request->validated(), 'updated FAQ', subjectLabel: mb_substr($faq->question, 0, 120));

        return ApiResponse::success(new FaqResource($faq), 'FAQ updated.');
    }

    public function destroyFaq(int $id): JsonResponse
    {
        $faq = Faq::query()->findOrFail($id);
        $this->audit->log('faq.deleted', $faq, $faq->only(['product', 'question']), null, 'deleted FAQ', subjectLabel: mb_substr($faq->question, 0, 120));
        $faq->delete();

        return ApiResponse::success(null, 'FAQ deleted.');
    }

    public function testimonials(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Testimonial::query()
            ->with('avatar')
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w->where('author_name', 'like', "%{$term}%")->orWhere('quote', 'like', "%{$term}%")))
            ->when($list->bool('is_published') !== null, fn ($q) => $q->where('is_published', $list->bool('is_published')))
            ->orderBy('position')
            ->orderBy('id');

        return ApiResponse::paginated($list->paginate($query, 25), TestimonialResource::class);
    }

    public function updateTestimonial(Request $request, int $id): JsonResponse
    {
        $testimonial = Testimonial::query()->findOrFail($id);
        $validated = $request->validate(['is_published' => ['required', 'boolean']]);

        $old = ['is_published' => $testimonial->is_published];
        $testimonial->fill($validated)->save();
        $this->audit->log('testimonial.updated', $testimonial, $old, $validated, $validated['is_published'] ? 'published testimonial' : 'hid testimonial', subjectLabel: $testimonial->author_name);

        return ApiResponse::success(new TestimonialResource($testimonial->load('avatar')), 'Testimonial updated.');
    }
}
