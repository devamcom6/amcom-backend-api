<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FaqProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Http\Resources\FaqResource;
use App\Http\Resources\TestimonialResource;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Testimonial;
use App\Services\Leads\LeadService;
use App\Services\LookupService;
use App\Support\ApiResponse;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Contract §13 — public content, lookups and contact. */
class ContentController extends Controller
{
    private const DEFAULT_STATS = [
        ['value' => '15+', 'label' => 'Years of Operating'],
        ['value' => '1200+', 'label' => 'Clients served'],
        ['value' => '₦2B+', 'label' => 'Value under management'],
        ['value' => '500+', 'label' => 'Properties listed and sold'],
    ];

    public function home(Request $request): JsonResponse
    {
        $stats = Setting::query()->where('key', 'home_stats')->value('value') ?? self::DEFAULT_STATS;

        $testimonials = Testimonial::query()->where('is_published', true)->with('avatar')->orderBy('position')->orderBy('id')->limit(20)->get();

        return ApiResponse::success([
            'stats' => $stats,
            'testimonials' => TestimonialResource::collection($testimonials)->resolve($request),
        ]);
    }

    public function faqs(Request $request): JsonResponse
    {
        $request->validate(['product' => ['nullable', Rule::enum(FaqProduct::class)]]);

        $faqs = Faq::query()
            ->where('is_published', true)
            ->when($request->query('product'), fn ($q, $product) => $q->where('product', $product))
            ->orderBy('position')
            ->orderBy('id')
            ->limit(200)
            ->get();

        return ApiResponse::success(FaqResource::collection($faqs)->resolve($request));
    }

    public function page(string $slug): JsonResponse
    {
        $page = Page::query()->where('slug', $slug)->firstOrFail();

        return ApiResponse::success([
            'slug' => $page->slug,
            'title' => $page->title,
            'body_html' => $page->body_html,
            'updated_at' => Format::iso($page->updated_at),
        ]);
    }

    public function lookups(LookupService $lookups): JsonResponse
    {
        return ApiResponse::success($lookups->all())->setPublic()->setMaxAge(3600);
    }

    public function contact(ContactRequest $request, LeadService $leads): JsonResponse
    {
        $message = $leads->contact($request->validated(), $this->optionalUser());

        return ApiResponse::created(['reference' => $message->reference], 'Thanks for reaching out. We will get back to you shortly.');
    }
}
