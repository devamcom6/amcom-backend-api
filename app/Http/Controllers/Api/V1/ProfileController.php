<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IdType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ContactDetailsRequest;
use App\Http\Requests\Profile\NextOfKinRequest;
use App\Http\Requests\Profile\PersonalInfoRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\VerifyIdentityRequest;
use App\Http\Requests\Rules;
use App\Http\Resources\MediaResource;
use App\Http\Resources\ProfileResource;
use App\Services\Media\MediaService;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §5.1–§5.7 */
class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->profiles->profileFor($user);

        return ApiResponse::success(new ProfileResource($user->fresh(['profile', 'latestIdentityVerification', 'avatar'])));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profiles->update($request->user(), $request->validated());

        return ApiResponse::success(new ProfileResource($user), 'Profile updated successfully.');
    }

    public function avatar(Request $request, MediaService $media): JsonResponse
    {
        $request->validate(['avatar' => Rules::image((int) config('amcom.media.max_avatar_kb'), 100, 100)]);

        $avatar = $media->replaceAvatar($request->user(), $request->file('avatar'));

        return ApiResponse::success(new MediaResource($avatar), 'Profile photo updated.');
    }

    public function verifyIdentity(VerifyIdentityRequest $request): JsonResponse
    {
        $verification = $this->profiles->verifyIdentity(
            $request->user(),
            IdType::from($request->validated('id_type')),
            $request->validated('id_number'),
        );

        return ApiResponse::success([
            'status' => $verification->status->value,
            'id_type' => $verification->id_type->value,
            'first_name' => $verification->first_name,
            'last_name' => $verification->last_name,
            'date_of_birth' => Format::ymd($verification->date_of_birth),
            'gender' => $verification->gender,
            'nationality' => $verification->nationality,
            'locked_fields' => $verification->lockedFields(),
        ], $verification->status->value === 'verified' ? 'Identity verified successfully.' : 'Verification in progress.');
    }

    public function personal(PersonalInfoRequest $request): JsonResponse
    {
        return ApiResponse::success(new ProfileResource($this->profiles->updatePersonal($request->user(), $request->validated())), 'Personal information saved.');
    }

    public function contact(ContactDetailsRequest $request): JsonResponse
    {
        return ApiResponse::success(new ProfileResource($this->profiles->updateContact($request->user(), $request->validated())), 'Contact details saved.');
    }

    public function nextOfKin(NextOfKinRequest $request): JsonResponse
    {
        return ApiResponse::success(new ProfileResource($this->profiles->updateNextOfKin($request->user(), $request->validated())), 'Next of kin saved.');
    }
}
