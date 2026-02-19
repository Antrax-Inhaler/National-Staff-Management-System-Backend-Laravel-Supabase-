<?php

namespace App\Http\Controllers\v1;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AffiliateOfficer;
use App\Models\Member;
use App\Models\Domain;
use App\Models\OfficerPosition;
use App\Models\User;
use App\Services\MemberIdGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
// REMOVED: use Illuminate\Support\Facades\Cache;

class ProfileController extends Controller
{
    public function getProfileData(): JsonResponse
    {
        try {
            $userId = Auth::id();

            // REMOVED CACHING: Direct database query without caching

            // Get member with optimized eager loading
            $member = Member::with([
                'affiliate:id,name,logo_url',
                'user:id,email,supabase_uid,created_at',
                'creator:id,name,email',
                'updater:id,name,email'
            ])
                ->where('user_id', $userId)
                ->first();

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found'
                ], 404);
            }

            // Get member info
            $memberData = $member->toArray();

            // Get officer positions and roles in parallel (single query)
            $officer = AffiliateOfficer::where('member_id', $member->id)
                ->with('position:id,name')
                ->current()
                ->first();

            // FIXED: Specify table for id column to avoid ambiguity
            $roles = $member->user
                ->roles()
                ->whereNotIn('roles.name', [RoleEnum::AFFILIATE_MEMBER->value, RoleEnum::AFFILIATE_OFFICER->value])
                ->get(['roles.id', 'roles.name']); // Specify table for id

            $formattedRoles = $roles->map(function ($role) {
                return [
                    'id'   => $role->id,
                    'type' => 'national',
                    'name' => $role->name,
                ];
            });

            $positions = [];

            if ($officer) {
                $positions[] = [
                    'id'   => $officer->position_id,
                    'type' => 'affiliate',
                    'name' => $officer->position->name ?? 'Unknown Position',
                ];
            }

            $positions = array_merge($positions, $formattedRoles->toArray());

            $memberData['roles'] = $positions;

            // Return the file path directly - let frontend handle URL generation
            $memberData['photo_url'] = $member->profile_photo_url
                ? Storage::disk('supabase')->temporaryUrl(
                    $member->profile_photo_url,
                    now()->addMinutes(10)
                )
                : null; // or default avatar

            // Get affiliate logo URL if exists
            if ($member->affiliate && $member->affiliate->logo_url) {
                try {
                    $memberData['affiliate_logo_url'] = Storage::disk('supabase')->temporaryUrl(
                        $member->affiliate->logo_url,
                        now()->addMinutes(10)
                    );
                } catch (\Exception $e) {
                    $memberData['affiliate_logo_url'] = null;
                    Log::warning('Failed to generate affiliate logo URL: ' . $e->getMessage());
                }
            } else {
                $memberData['affiliate_logo_url'] = null;
            }

            // Add affiliate name for display
            $memberData['affiliate_name'] = $member->affiliate ? $member->affiliate->name : null;
            // Add display name
            $memberData['display_name'] = $member->first_name . ' ' . $member->last_name;

            // Calculate missing data count (optimized)
            $missingData = $this->calculateMissingDataCount($member);

            return response()->json([
                'success' => true,
                'data' => [
                    'info' => $memberData,
                    'missing_data' => $missingData,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('getProfileData error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch profile data'
            ], 500);
        }
    }

    /**
     * Optimized missing data calculation
     */
    private function calculateMissingDataCount(Member $member): array
    {
        // Required fields - using direct property access for speed
        // TEMPORARILY MADE date_of_birth, gender, self_id NON-REQUIRED
        $requiredFields = [
            'member_id' => $member->member_id,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'work_email' => $member->work_email,
            'address_line1' => $member->address_line1,
            'city' => $member->city,
            'state' => $member->state,
            'zip_code' => $member->zip_code,
            'mobile_phone' => $member->mobile_phone,
        ];

        // Recommended fields (temporarily non-required)
        $recommendedFields = [
            'date_of_birth' => $member->date_of_birth,
            'gender' => $member->gender,
            'self_id' => $member->self_id,
            'home_phone' => $member->home_phone,
            'address_line2' => $member->address_line2,
        ];

        // Count missing fields efficiently
        $missingFields = [];
        $missingCount = 0;

        foreach ($requiredFields as $field => $value) {
            if (empty($value) && $value !== 0 && $value !== '0') {
                $missingCount++;
                $missingFields[] = $field;
            }
        }

        $recommendedMissing = 0;
        foreach ($recommendedFields as $field => $value) {
            if (empty($value) && $value !== 0 && $value !== '0') {
                $recommendedMissing++;
                $missingFields[] = $field;
            }
        }

        $totalRequired = count($requiredFields);
        $completionPercentage = $missingCount > 0
            ? round((1 - ($missingCount / $totalRequired)) * 100)
            : 100;

        return [
            'missing_count' => $missingCount,
            'recommended_missing' => $recommendedMissing,
            'total_required_fields' => $totalRequired,
            'total_fields' => $totalRequired + count($recommendedFields),
            'missing_fields' => $missingFields,
            'completion_percentage' => $completionPercentage
        ];
    }

    /**
     * Keep separate endpoints for backward compatibility
     */
    public function info(): JsonResponse
    {
        try {
            $userId = Auth::id();

            $member = Member::with([
                'affiliate:id,name,logo_url',
                'user:id,email,supabase_uid,created_at',
                'creator:id,name,email',
                'updater:id,name,email'
            ])
                ->where('user_id', $userId)
                ->first();

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found'
                ], 404);
            }

            $memberData = $member->toArray();

            // Get officer positions and roles
            $officers = AffiliateOfficer::where('member_id', $member->id)
                ->with('position:id,name')
                ->current()
                ->get();

            $roles = $member->user
                ->roles()
                ->whereNotIn('roles.name', [RoleEnum::AFFILIATE_MEMBER->value, RoleEnum::AFFILIATE_OFFICER->value])
                ->get(['roles.id', 'roles.name']);

            $formattedRoles = $roles->map(function ($role) {
                return [
                    'id'   => $role->id,
                    'type' => 'national',
                    'name' => $role->name,
                ];
            });

            $positions = [];

            foreach ($officers as $officer) {
                $positions[] = [
                    'id'   => $officer->position_id,
                    'type' => 'affiliate',
                    'name' => $officer->position->name ?? 'Unknown Position',
                ];
            }

            $positions = array_merge($positions, $formattedRoles->toArray());

            $memberData['roles'] = $positions;

            // Generate photo URL
            $memberData['photo_url'] = $member->profile_photo_url
                ? Storage::disk('supabase')->temporaryUrl(
                    $member->profile_photo_url,
                    now()->addMinutes(10)
                )
                : null;

            // Add display name
            $memberData['display_name'] = $member->first_name . ' ' . $member->last_name;
            $memberData['affiliate_name'] = $member->affiliate ? $member->affiliate->name : null;

            return response()->json([
                'success' => true,
                'data' => $memberData
            ]);
        } catch (\Exception $e) {
            Log::error('info error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch profile info'
            ], 500);
        }
    }

    /**
     * Missing data count
     */
    public function getMissingDataCount(): JsonResponse
    {
        try {
            $member = Member::where('user_id', Auth::id())->first();

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found',
                    'missing_count' => 0
                ]);
            }

            $missingData = $this->calculateMissingDataCount($member);

            return response()->json(array_merge(
                ['success' => true],
                $missingData
            ));
        } catch (\Exception $e) {
            Log::error('getMissingDataCount error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get missing data count',
                'missing_count' => 0
            ], 500);
        }
    }

    public function update(Request $request, Member $member)
    {
        // Get the current member
        $currentMember = Member::where('user_id', Auth::id())->first();

        if (!$currentMember) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        // Get the user to check current email
        $user = User::findOrFail($currentMember->user_id);

        // Define validation rules for all fields from frontend
        // TEMPORARILY REMOVED REQUIRED FOR: date_of_birth, gender, self_id
        $validated = $request->validate([
            'member_id' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'work_email' => [
                'required',
                'string',
                'email',
                Rule::unique('users', 'email')->ignore($user->id), // Check unique in users table
                Rule::unique('members', 'work_email')->ignore($currentMember->id), // Check unique in members table
                function ($attribute, $value, $fail) use ($currentMember) {
                    // Use the current member's affiliate_id
                    $affiliate_id = $currentMember->affiliate_id;

                    // Extract domain and TLD
                    $domain = substr(strrchr($value, '@'), 1); // e.g., example.org
                    $tld = '.' . substr(strrchr($domain, '.'), 1); // e.g., ".org"
                    $domainToCheck = [$domain, $tld];

                    // Get blacklisted domains/TLDs for this affiliate
                    $blacklisted = Domain::where('is_blacklisted', true)
                        ->where('affiliate_id', $affiliate_id)
                        ->pluck('domain')
                        ->toArray();

                    // Fail if domain or TLD is blacklisted
                    if (array_intersect($domainToCheck, $blacklisted)) {
                        $fail("The email domain \"$domain\" is blacklisted.");
                        return;
                    }

                    // Only enforce allowed domains for .org emails
                    if ($tld === '.org') {
                        $allowed = Domain::where('is_blacklisted', false)
                            ->where('affiliate_id', $affiliate_id)
                            ->pluck('domain')
                            ->toArray();

                        if (!empty($allowed) && !array_intersect($domainToCheck, $allowed)) {
                            $fail("The .org email domain \"$domain\" is not allowed for this affiliate.");
                        }
                    }
                },
            ],
            'address_line1' => 'required|string|max:500',
            'address_line2' => 'nullable|string|max:500',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'state_id' => 'nullable|integer',
            'zip_code' => 'required|string|max:10',
            'mobile_phone' => 'required|string|max:255',
            'home_phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date', // TEMPORARILY NON-REQUIRED
            'gender' => 'nullable|string|in:male,female,non-binary,none_of_these_choices,prefer_not_to_disclose', // UPDATED OPTIONS
            'self_id' => 'nullable|string|max:255', // TEMPORARILY NON-REQUIRED
        ]);

        try {
            DB::beginTransaction();

            // Update member with all validated fields
            $updateData = $validated;

            $updateData['updated_by'] = Auth::id();
            $updateData['home_email'] = $validated['work_email']; // Keep home_email in sync

            Log::info('Profile update fired in controller');

            // Update user email
            $user->update([
                'email' => $validated['work_email']
            ]);

            // Update member
            $currentMember->update($updateData);

            DB::commit();

            // Return the updated member
            $updatedMember = Member::with([
                'affiliate:id,name',
                'user:id,email',
                'creator:id,name,email',
                'updater:id,name,email'
            ])->find($currentMember->id);

            // Generate Supabase URL for profile photo
            if ($updatedMember->profile_photo_url) {
                try {
                    $updatedMember->profile_photo_url = Storage::disk('supabase')->url($updatedMember->profile_photo_url);
                } catch (\Exception $e) {
                    Log::warning('Failed to generate URL for updated profile photo: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $updatedMember
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('updateProfile error: ' . $e->getMessage());
            return response()->json(['message' => 'Server error'], 500);
        }
    }

    public function uploadProfilePhoto(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'profile_photo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ]);

            $member = Member::where('user_id', Auth::id())->first();

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found'
                ], 404);
            }

            $file = $request->file('profile_photo');

            // Upload to Supabase storage
            $extension = $file->getClientOriginalExtension();
            $fileName = 'profile_' . time() . '_' . uniqid() . '.' . $extension;
            $filePath = 'profile-photos/' . $fileName;

            // Store in Supabase
            Storage::disk('supabase')->put($filePath, file_get_contents($file));

            // Get the public URL from Supabase
            $publicUrl = Storage::disk('supabase')->url($filePath);

            // Delete old photo if exists
            if ($member->profile_photo_url) {
                try {
                    Storage::disk('supabase')->delete($member->profile_photo_url);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete old profile photo: ' . $e->getMessage());
                }
            }

            // Store the file path in database
            $member->update([
                'profile_photo_url' => $filePath, // Store the path, not full URL
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile photo uploaded successfully',
                'data' => $member->fresh(['affiliate:id,name', 'user:id,email'])
            ], 200);
        } catch (\Exception $e) {
            Log::error('Profile photo upload error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload profile photo: ' . $e->getMessage()
            ], 500);
        }
    }

    private function extractFilePath($url): string
    {
        // If it's a full URL, extract just the path part
        $parsed = parse_url($url);
        return $parsed['path'] ?? $url;
    }

    public function deleteProfilePhoto(): JsonResponse
    {
        try {
            $member = Member::where('user_id', Auth::id())->first();

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found'
                ], 404);
            }

            if ($member->profile_photo_url) {
                try {
                    // Delete from Supabase storage
                    Storage::disk('supabase')->delete($member->profile_photo_url);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete profile photo from storage: ' . $e->getMessage());
                }
            }

            $member->update([
                'profile_photo_url' => null,
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile photo deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Profile photo deletion error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete profile photo'
            ], 500);
        }
    }

    public function streamProfilePhoto(Request $request, $memberId = null): \Symfony\Component\HttpFoundation\Response
    {
        try {
            // If no memberId provided, use authenticated user's member ID
            if (!$memberId) {
                $member = Member::where('user_id', Auth::id())->first();
                $memberId = $member->id ?? null;
            } else {
                $member = Member::find($memberId);
            }

            if (!$member || !$member->profile_photo_url) {
                return $this->getDefaultAvatar();
            }

            // Check if file exists in storage
            if (!Storage::disk('supabase')->exists($member->profile_photo_url)) {
                Log::warning('Profile photo file not found in storage: ' . $member->profile_photo_url);
                return $this->getDefaultAvatar();
            }

            // Get file contents from storage
            $fileContents = Storage::disk('supabase')->get($member->profile_photo_url);

            if (!$fileContents) {
                Log::warning('Failed to get file contents for: ' . $member->profile_photo_url);
                return $this->getDefaultAvatar();
            }

            // Determine content type from file extension
            $extension = pathinfo($member->profile_photo_url, PATHINFO_EXTENSION);
            $contentType = $this->getContentType($extension);

            // Get file size for headers
            $fileSize = Storage::disk('supabase')->size($member->profile_photo_url);

            // Return image response with proper headers
            return response($fileContents, 200, [
                'Content-Type' => $contentType,
                'Content-Length' => $fileSize,
                'Content-Disposition' => 'inline; filename="profile-photo"',
                'Cache-Control' => 'public, max-age=3600',
                'Expires' => now()->addHours(1)->format('D, d M Y H:i:s \G\M\T'),
                'Pragma' => 'cache',
            ]);
        } catch (\Exception $e) {
            Log::error('Stream profile photo error: ' . $e->getMessage());
            return $this->getDefaultAvatar();
        }
    }

    /**
     * NEW: Get blob URL for profile photo
     */
    public function getProfilePhotoUrl(Request $request): JsonResponse
    {
        try {
            $member = Member::where('user_id', Auth::id())->first();

            if (!$member || !$member->profile_photo_url) {
                return response()->json([
                    'success' => false,
                    'message' => 'No profile photo found'
                ], 404);
            }

            // Generate stream URL instead of signed URL
            $streamUrl = $this->generateStreamUrl($member->id);

            return response()->json([
                'success' => true,
                'data' => [
                    'stream_url' => $streamUrl,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get profile photo URL error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile photo URL'
            ], 500);
        }
    }

    /**
     * Helper method to generate blob URL for profile photo
     * This creates a temporary blob URL that works like: blob:http://127.0.0.1:5173/5abc22e1-0e4d-4fc1-b931-36ab4f69dd31
     */
    private function generateBlobUrl(string $filePath): string
    {
        try {
            // Get file contents from storage
            $fileContents = Storage::disk('supabase')->get($filePath);

            if (!$fileContents) {
                throw new \Exception('File not found in storage');
            }

            // Determine content type
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $contentType = match (strtolower($extension)) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'image/jpeg'
            };

            // Create temporary file and return blob URL
            $tempFile = tmpfile();
            fwrite($tempFile, $fileContents);
            $tempFileUri = stream_get_meta_data($tempFile)['uri'];

            // Read the file and create blob
            $blobData = file_get_contents($tempFileUri);
            $blob = new \Illuminate\Http\File($tempFileUri);

            // Close temporary file
            fclose($tempFile);

            // For now, we'll use the stream endpoint as blob URLs are client-side
            // In a real implementation, you'd use JavaScript to create blob URLs
            $baseUrl = config('app.url') ?? 'http://localhost:8000';
            return $baseUrl . '/api/v1/profile/photo/stream';
        } catch (\Exception $e) {
            Log::warning('Failed to generate blob URL: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Helper method to generate stream URL for profile photo
     */
    private function generateStreamUrl(int $memberId): string
    {
        $baseUrl = config('app.url') ?? 'http://localhost:8000';
        return $baseUrl . '/api/v1/profile/photo/stream/' . $memberId;
    }

    private function getContentType(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg'
        };
    }

    private function getDefaultAvatar(): \Symfony\Component\HttpFoundation\Response
    {
        $svg = '<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" font-family="Arial" font-size="14" fill="#9ca3af" text-anchor="middle" dy=".3em">No Image</text></svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    private function deletePhotoFromStorage(string $filePath): void
    {
        try {
            if (Storage::disk('supabase')->exists($filePath)) {
                Storage::disk('supabase')->delete($filePath);
                Log::info('Profile photo deleted from Supabase', ['filePath' => $filePath]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete profile photo from storage: ' . $e->getMessage());
        }
    }

    public function downloadProfilePhoto(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $filePath = $request->query('path');

            if (!$filePath) {
                $member = Member::where('user_id', Auth::id())->first();
                $filePath = $member->profile_photo_url ?? '';
            }

            if (!$filePath || !Storage::disk('supabase')->exists($filePath)) {
                return $this->getDefaultAvatar();
            }

            $fileContents = Storage::disk('supabase')->get($filePath);
            $fileSize = Storage::disk('supabase')->size($filePath);
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $contentType = $this->getContentType($extension);

            return response($fileContents, 200, [
                'Content-Type' => $contentType,
                'Content-Length' => $fileSize,
                'Content-Disposition' => 'inline; filename="profile-photo"',
            ]);
        } catch (\Exception $e) {
            Log::error('Download profile photo error: ' . $e->getMessage());
            return $this->getDefaultAvatar();
        }
    }

    public function deletePhoto(): JsonResponse
    {
        try {
            $member = Member::where('user_id', Auth::id())->first();

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found'
                ], 404);
            }

            if ($member->profile_photo_url) {
                Storage::disk('supabase')->delete($member->profile_photo_url);
            }

            $member->update([
                'profile_photo_url' => null,
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile photo deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Delete profile photo error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete profile photo'
            ], 500);
        }
    }

    private function isDomainBlacklisted($email)
    {
        if (!$email) return false;

        // Extract domain from email
        $domain = substr(strrchr($email, "@"), 1);

        if (!$domain) return false;

        // Check if domain exists in blacklist
        $blacklisted = Domain::blacklisted()
            ->where('domain', $domain)
            ->exists();

        return $blacklisted;
    }

    public function generateId()
    {
        try {
            $user = Auth::user();
            $member = $user->member;

            if (! $member) {
                return response()->json([
                    'success' => false,
                    'message' => 'User has no member record',
                ], 404);
            }

            if ($member->member_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member already has a Member ID',
                ], 409);
            }

            $memberId = app(MemberIdGeneratorService::class)->generate(
                affiliate: $member->affiliate_id,
                memberId: $member->id
            );

            $member->update([
                'member_id' => $memberId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Successfully generated Member ID',
                'member_id' => $memberId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Member ID generation failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate Member ID',
            ], 500);
        }
    }
}
