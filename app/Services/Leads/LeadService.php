<?php

namespace App\Services\Leads;

use App\Enums\InspectionType;
use App\Enums\PlotStatus;
use App\Enums\Product;
use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Models\Estate;
use App\Models\Plot;
use App\Models\Property;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Investments\BuybackService;
use App\Services\Investments\CapitalService;
use App\Services\Notifier;
use App\Support\Format;
use App\Support\IcsCalendar;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Lead capture and service requests (interests, applications, inspections, contact, support).
 * Every record also appears in the admin requests queue (contract §15.7).
 */
class LeadService
{
    public function __construct(
        private readonly BuybackService $buyback,
        private readonly CapitalService $capital,
        private readonly Notifier $notifier,
    ) {}

    public function buybackInterest(array $data, ?User $user): ServiceRequest
    {
        $estate = Estate::query()->published()->with('tenures')->find($data['estate_id']);

        if ($estate === null) {
            throw ApiException::field('estate_id', 'The selected estate is not available.');
        }

        $tenure = $this->buyback->assertSelection($estate, $data['plot_ids'], (int) $data['tenure_months']);
        $plots = Plot::query()->whereIn('id', $data['plot_ids'])->orderBy('code')->get();

        foreach ($plots as $plot) {
            if ($plot->effectiveStatus() !== PlotStatus::Available) {
                throw ApiException::conflict("Plot {$plot->code} is no longer available. Please choose another.", 'plot_unavailable');
            }
        }

        $summary = $estate->name.' — '.$plots->pluck('code')->implode(', ').', '.$tenure->months.' months';

        $request = $this->store(RequestKind::Interest, 'INT', $data, $user, [
            'product' => Product::Buyback,
            'subject_line' => 'Buyback interest — '.$estate->name.' ('.$plots->count().' '.str('plot')->plural($plots->count()).')',
            'message' => $data['note'] ?? null,
            'meta' => [
                'plot_ids' => $plots->modelKeys(),
                'plot_codes' => $plots->pluck('code')->all(),
                'tenure_months' => $tenure->months,
                'roi_percent' => $tenure->roi_percent,
                'indicative_price' => (int) $plots->sum('price'),
            ],
        ], $estate);

        $this->notifier->email($request->email, 'interest_received', [
            'name' => $request->name, 'reference' => $request->reference,
            'product_label' => 'Buyback', 'summary' => $summary,
        ]);
        $this->notifier->ops('new_lead', 'New buyback interest: '.$estate->name, [
            'reference' => $request->reference, 'name' => $request->name, 'email' => $request->email,
            'phone' => $request->phone, 'selection' => $summary, 'note' => $request->message,
        ], '/admin/requests?kind[]=interest');

        return $request;
    }

    public function capitalInterest(array $data, ?User $user): ServiceRequest
    {
        $this->capital->assertAmount((int) $data['amount']);
        $roi = $this->capital->roiFor((int) $data['tenure_months']);
        $summary = Format::naira($data['amount']).' for '.$data['tenure_months'].' months';

        $request = $this->store(RequestKind::Interest, 'INT', $data, $user, [
            'product' => Product::CapitalInvestment,
            'subject_line' => 'Capital investment interest — '.$summary,
            'message' => $data['note'] ?? null,
            'meta' => ['amount' => (int) $data['amount'], 'tenure_months' => (int) $data['tenure_months'], 'roi_percent' => $roi],
        ]);

        $this->notifier->email($request->email, 'interest_received', [
            'name' => $request->name, 'reference' => $request->reference,
            'product_label' => 'Capital investment', 'summary' => $summary,
        ]);
        $this->notifier->ops('new_lead', 'New capital investment interest', [
            'reference' => $request->reference, 'name' => $request->name, 'email' => $request->email,
            'phone' => $request->phone, 'selection' => $summary, 'note' => $request->message,
        ], '/admin/requests?kind[]=interest');

        return $request;
    }

    public function application(Property $property, array $data, ?User $user): ServiceRequest
    {
        $request = $this->store(RequestKind::Application, 'APP', $data, $user, [
            'subject_line' => 'Application — '.$property->title,
            'message' => $data['message'],
            'meta' => ['listing_type' => $property->listing_type->value],
        ], $property);

        $this->notifier->email($request->email, 'application_received', [
            'name' => $request->name, 'reference' => $request->reference, 'property_title' => $property->title,
        ]);

        if ($property->owner && $property->owner->id !== $user?->id) {
            $this->notifier->user($property->owner, 'new_inquiry', ['property_title' => $property->title, 'listing_id' => $property->id]);
        }

        $this->notifier->ops('new_application', 'New application: '.$property->title, [
            'reference' => $request->reference, 'name' => $request->name, 'email' => $request->email, 'phone' => $request->phone,
        ], '/admin/requests?kind[]=application');

        return $request;
    }

    /**
     * Bookable slots for the next N days (skipping Sundays and past/booked slots).
     *
     * @return list<array{date: string, times: list<string>}>
     */
    public function inspectionAvailability(Property $property): array
    {
        $tz = config('amcom.inspections.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $days = (int) config('amcom.inspections.days_ahead');
        $times = config('amcom.inspections.times');

        $booked = ServiceRequest::query()
            ->where('kind', RequestKind::Inspection)
            ->whereMorphedTo('subject', $property)
            ->whereNotIn('status', [RequestStatus::Closed])
            ->where('scheduled_for', '>=', $today->utc())
            ->pluck('scheduled_for')
            ->map(fn ($dt) => CarbonImmutable::parse($dt)->timezone($tz)->format('Y-m-d H:i'))
            ->flip();

        $result = [];
        $cursor = $today->addDay();

        while (count($result) < $days) {
            if (! (config('amcom.inspections.skip_sundays') && $cursor->isSunday())) {
                $free = array_values(array_filter($times, fn ($t) => ! isset($booked[$cursor->format('Y-m-d').' '.$t])));

                if ($free !== []) {
                    $result[] = ['date' => $cursor->toDateString(), 'times' => $free];
                }
            }

            $cursor = $cursor->addDay();

            if ($cursor->diffInDays($today) > $days * 3) {
                break; // safety net
            }
        }

        return $result;
    }

    /** @return array{request: ServiceRequest, ics: string} */
    public function bookInspection(Property $property, array $data, ?User $user): array
    {
        $tz = config('amcom.inspections.timezone');
        $scheduled = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time'], $tz);

        $request = DB::transaction(function () use ($property, $data, $user, $scheduled) {
            // Serialise bookings per property so a slot can't be double-booked.
            Property::query()->whereKey($property->id)->lockForUpdate()->first();

            $slotFree = collect($this->inspectionAvailability($property))
                ->contains(fn ($day) => $day['date'] === $data['date'] && in_array($data['time'], $day['times'], true));

            if (! $slotFree) {
                throw ApiException::field('time', 'This time slot is no longer available. Please choose another.');
            }

            $type = InspectionType::from($data['type']);

            return $this->store(RequestKind::Inspection, 'INS', $data, $user, [
                'subject_line' => ($type === InspectionType::Virtual ? 'Virtual' : 'In-person').' inspection — '.$property->title,
                'message' => $data['message'] ?? null,
                'scheduled_for' => $scheduled->utc(),
                'meta' => ['type' => $type->value, 'attendee' => $data['attendee']],
            ], $property);
        });

        $ics = IcsCalendar::event(
            'inspection-'.$request->id.'@amcom',
            'Property inspection — '.$property->title,
            ucfirst(str_replace('_', '-', $data['type'])).' inspection. Reference '.$request->reference,
            $data['type'] === InspectionType::Virtual->value ? 'Virtual (link will be shared by email)' : $property->address,
            $scheduled,
        );

        $label = $scheduled->format('l, j M Y \a\t g:i A');
        $typeLabel = $data['type'] === InspectionType::Virtual->value ? 'virtual' : 'in-person';

        $this->notifier->email($request->email, 'inspection_booked', [
            'name' => $request->name, 'reference' => $request->reference, 'property_title' => $property->title,
            'inspection_type' => $typeLabel, 'scheduled_label' => $label,
        ], [['name' => 'inspection-'.$request->reference.'.ics', 'mime' => 'text/calendar', 'content' => $ics]]);

        $this->notifier->ops('inspection_booked', 'Inspection booked: '.$property->title, [
            'reference' => $request->reference, 'when' => $label, 'type' => $typeLabel,
            'name' => $request->name, 'phone' => $request->phone,
        ], '/admin/requests?kind[]=inspection');

        return ['request' => $request, 'ics' => $ics];
    }

    public function calendarUrl(ServiceRequest $request): string
    {
        return URL::temporarySignedRoute('inspections.calendar', now()->addDays(30), ['serviceRequest' => $request->id]);
    }

    public function calendarFor(ServiceRequest $request): string
    {
        /** @var Property|null $property */
        $property = $request->subject;

        return IcsCalendar::event(
            'inspection-'.$request->id.'@amcom',
            'Property inspection — '.($property instanceof Property ? $property->title : 'Amcom property'),
            'Reference '.$request->reference,
            ($request->meta['type'] ?? null) === InspectionType::Virtual->value ? 'Virtual' : ($property instanceof Property ? $property->address : ''),
            CarbonImmutable::parse($request->scheduled_for),
        );
    }

    public function contact(array $data, ?User $user): ServiceRequest
    {
        $request = $this->store(RequestKind::Contact, 'MSG', $data, $user, [
            'subject_line' => $data['subject'],
            'message' => $data['message'],
        ]);

        $this->notifier->email($request->email, 'contact_received', [
            'name' => $request->name, 'reference' => $request->reference, 'subject' => $data['subject'],
        ]);
        $this->notifier->ops('contact', 'Contact form: '.$data['subject'], [
            'reference' => $request->reference, 'name' => $request->name, 'email' => $request->email, 'message' => $request->message,
        ], '/admin/requests?kind[]=contact');

        return $request;
    }

    public function supportTicket(User $user, array $data): ServiceRequest
    {
        $request = $this->store(RequestKind::Support, 'SUP', [
            'full_name' => $user->displayName(),
            'email' => $user->email,
            'phone' => $user->phone,
        ], $user, [
            'subject_line' => $data['subject'],
            'message' => $data['message'],
            'meta' => ['category' => $data['category']],
        ]);

        $this->notifier->user($user, 'support_received', ['reference' => $request->reference, 'subject' => $data['subject']]);
        $this->notifier->ops('support', 'Support ticket ('.$data['category'].'): '.$data['subject'], [
            'reference' => $request->reference, 'customer' => $user->email, 'message' => $data['message'],
        ], '/admin/requests?kind[]=support');

        return $request;
    }

    private function store(RequestKind $kind, string $prefix, array $data, ?User $user, array $attributes, ?Model $subject = null): ServiceRequest
    {
        return DB::transaction(function () use ($kind, $prefix, $data, $user, $attributes, $subject) {
            $request = new ServiceRequest($attributes + [
                'kind' => $kind,
                'reference' => Reference::temporary(),
                'user_id' => $user?->id,
                'name' => $data['full_name'],
                'email' => mb_strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'alt_phone' => $data['alt_phone'] ?? null,
                'status' => RequestStatus::New,
            ]);

            if ($subject !== null) {
                $request->subject()->associate($subject);
            }

            $request->save();
            $request->forceFill(['reference' => Reference::short($prefix, $request->id)])->save();

            return $request;
        });
    }
}
