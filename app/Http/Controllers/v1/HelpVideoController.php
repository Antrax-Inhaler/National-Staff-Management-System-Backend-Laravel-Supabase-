<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\HelpVideoResource;
use App\Models\HelpVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HelpVideoController extends Controller
{
    const CATEGORIES = [
        'General',
        'Members',
        'Affiliates', 
        'Organization Leaders',
        'Audit Logs',
        'Documents',
        'Links',
        'Information',
        'Officers',
        'Configuration',
        'Others'
    ];

    public function index(Request $request)
    {
        try {
            Log::info('HELP VIDEOS INDEX REQUEST', [
                'request_params' => $request->all(),
                'user_id' => Auth::id(),
                'url' => $request->fullUrl(),
            ]);

            $query = HelpVideo::query()->with('creator:id,name,email');
            
            // Log initial query state
            Log::info('HELP VIDEOS - Initial query built', [
                'model_count' => HelpVideo::count(),
            ]);

            // Apply filters
            if ($request->has('category') && $request->category !== 'all') {
                $query->where('category', $request->category);
                Log::info('HELP VIDEOS - Applied category filter', [
                    'category' => $request->category,
                ]);
            }
            
            if ($request->has('search')) {
                $query->search($request->search);
                Log::info('HELP VIDEOS - Applied search filter', [
                    'search' => $request->search,
                ]);
            }
            
            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
                Log::info('HELP VIDEOS - Applied active filter', [
                    'is_active' => $request->is_active,
                    'filtered_value' => filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN),
                ]);
            }
            
            // REMOVED ROLE CHECKING - All users can see all videos
            // You can add this back later if needed
            
            $sort_by = $request->query('sort_by', 'created_at');
            $sort_order = $request->query('sort_order', 'desc');
            $perPage = $request->query('per_page', 12);

            Log::info('HELP VIDEOS - Pagination parameters', [
                'sort_by' => $sort_by,
                'sort_order' => $sort_order,
                'per_page' => $perPage,
                'page' => $request->query('page', 1),
            ]);

            $collection = ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order)
                ->useResource(HelpVideoResource::class);
            
            // Get the response data
            $responseData = $collection->toResponse($request)->getData(true);
            
            // Log the raw response before modifying URLs
            Log::info('HELP VIDEOS - Raw API collection response', [
                'total_items' => $responseData['meta']['total'] ?? 0,
                'current_page' => $responseData['meta']['current_page'] ?? 1,
                'last_page' => $responseData['meta']['last_page'] ?? 1,
                'data_count' => count($responseData['data'] ?? []),
                'has_data' => !empty($responseData['data']),
            ]);

            // Add signed URLs to each video
            if (isset($responseData['data'])) {
                Log::info('HELP VIDEOS - Processing video data for signed URLs', [
                    'videos_count' => count($responseData['data']),
                ]);
                
                foreach ($responseData['data'] as $index => &$video) {
                    Log::info('HELP VIDEOS - Processing video', [
                        'video_id' => $video['id'] ?? 'N/A',
                        'video_title' => $video['title'] ?? 'N/A',
                        'has_video_url_field' => isset($video['video_url']),
                        'has_thumbnail_url_field' => isset($video['thumbnail_url']),
                    ]);

                    if (isset($video['video_url'])) {
                        try {
                            // Get the raw path from database (not the signed URL if already exists)
                            $videoModel = HelpVideo::find($video['id']);
                            
                            if ($videoModel) {
                                $rawVideoUrl = $videoModel->getRawOriginal('video_url');
                                Log::info('HELP VIDEOS - Video model found', [
                                    'video_id' => $videoModel->id,
                                    'raw_video_url' => $rawVideoUrl,
                                ]);

                                if ($rawVideoUrl) {
                                    Log::info('HELP VIDEOS - Generating signed URL for video', [
                                        'video_id' => $videoModel->id,
                                        'raw_path' => $rawVideoUrl,
                                    ]);
                                    
                                    $video['video_url'] = Storage::disk('supabase')->temporaryUrl(
                                        $rawVideoUrl,
                                        now()->addMinutes(120)
                                    );
                                    
                                    Log::info('HELP VIDEOS - Signed URL generated for video', [
                                        'video_id' => $videoModel->id,
                                        'signed_url_length' => strlen($video['video_url']),
                                    ]);
                                } else {
                                    Log::warning('HELP VIDEOS - No raw video URL found in database', [
                                        'video_id' => $videoModel->id,
                                    ]);
                                    $video['video_url'] = null;
                                }
                            } else {
                                Log::warning('HELP VIDEOS - Video model not found for ID', [
                                    'video_id' => $video['id'],
                                ]);
                                $video['video_url'] = null;
                            }
                        } catch (\Exception $e) {
                            Log::error('Failed to generate video URL: ' . $e->getMessage(), [
                                'video_id' => $video['id'] ?? 'unknown',
                            ]);
                            $video['video_url'] = null;
                        }
                    }
                    
                    if (isset($video['thumbnail_url'])) {
                        try {
                            $videoModel = $videoModel ?? HelpVideo::find($video['id']);
                            
                            if ($videoModel) {
                                $rawThumbnailUrl = $videoModel->getRawOriginal('thumbnail_url');
                                Log::info('HELP VIDEOS - Processing thumbnail', [
                                    'video_id' => $videoModel->id,
                                    'raw_thumbnail_url' => $rawThumbnailUrl,
                                ]);

                                if ($rawThumbnailUrl) {
                                    Log::info('HELP VIDEOS - Generating signed URL for thumbnail', [
                                        'video_id' => $videoModel->id,
                                        'raw_path' => $rawThumbnailUrl,
                                    ]);
                                    
                                    $video['thumbnail_url'] = Storage::disk('supabase')->temporaryUrl(
                                        $rawThumbnailUrl,
                                        now()->addMinutes(60)
                                    );
                                    
                                    Log::info('HELP VIDEOS - Signed URL generated for thumbnail', [
                                        'video_id' => $videoModel->id,
                                        'signed_url_length' => strlen($video['thumbnail_url']),
                                    ]);
                                } else {
                                    Log::warning('HELP VIDEOS - No raw thumbnail URL found in database', [
                                        'video_id' => $videoModel->id,
                                    ]);
                                    $video['thumbnail_url'] = null;
                                }
                            }
                        } catch (\Exception $e) {
                            Log::error('Failed to generate thumbnail URL: ' . $e->getMessage(), [
                                'video_id' => $video['id'] ?? 'unknown',
                            ]);
                            $video['thumbnail_url'] = null;
                        }
                    }

                    // Log final state of this video
                    Log::info('HELP VIDEOS - Video processing complete', [
                        'video_id' => $video['id'] ?? 'N/A',
                        'final_video_url' => $video['video_url'] ? 'present' : 'null',
                        'final_thumbnail_url' => $video['thumbnail_url'] ? 'present' : 'null',
                        'category' => $video['category'] ?? 'N/A',
                        'is_active' => $video['is_active'] ?? 'N/A',
                    ]);
                }
            }

            // Log final response
            Log::info('HELP VIDEOS - Final response prepared', [
                'total_videos_returned' => count($responseData['data'] ?? []),
                'success' => true,
            ]);

            return response()->json($responseData);
        } catch (\Throwable $th) {
            Log::error('FETCHING HELP VIDEOS ERROR', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString(),
                'request_params' => $request->all(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $video = HelpVideo::with('creator:id,name,email')->findOrFail($id);
            
            // Generate signed URLs
            $this->generateSignedUrlsForVideo($video);
            
            return response()->json([
                'success' => true,
                'data' => new HelpVideoResource($video),
                'message' => 'Video retrieved successfully'
            ]);
        } catch (\Throwable $th) {
            Log::error('FETCHING HELP VIDEO', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Video not found',
            ], 404);
        }
    }

    public function showByPublicUid($publicUid)
    {
        try {
            $video = HelpVideo::with('creator:id,name,email')
                ->where('public_uid', $publicUid)
                ->firstOrFail();
            
            // Generate signed URLs
            $this->generateSignedUrlsForVideo($video);
            
            return response()->json([
                'success' => true,
                'data' => new HelpVideoResource($video),
                'message' => 'Video retrieved successfully'
            ]);
        } catch (\Throwable $th) {
            Log::error('FETCHING HELP VIDEO BY PUBLIC UID', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Video not found',
            ], 404);
        }
    }

    public function store(Request $request)
    {
        Log::info('HELP VIDEO - Starting upload', [
            'has_video' => $request->hasFile('video'),
            'has_thumbnail' => $request->hasFile('thumbnail'),
        ]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'video' => ['required', 'file', 'mimes:mp4,avi,mov,wmv,flv,mkv', 'max:512000'], // Max 500MB
            'thumbnail' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'], // Max 5MB
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            DB::beginTransaction();

            $videoData = [
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'category' => $validated['category'],
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => Auth::id(),
            ];

            // Upload video to Supabase - SIMPLER APPROACH
            if ($request->hasFile('video')) {
                Log::info('HELP VIDEO - Processing video file upload');
                
                // Use the simpler approach like OrganizationInformationController
                $videoPath = $request->file('video')->store('help-videos', 'supabase');
                $videoData['video_url'] = $videoPath;
                
                Log::info('HELP VIDEO - Video uploaded successfully to Supabase', [
                    'path' => $videoPath,
                    'size' => $request->file('video')->getSize(),
                ]);
            }

            // Upload thumbnail to Supabase if provided
            if ($request->hasFile('thumbnail')) {
                Log::info('HELP VIDEO - Processing thumbnail file upload');
                
                // Use the simpler approach
                $thumbnailPath = $request->file('thumbnail')->store('help-videos', 'supabase');
                $videoData['thumbnail_url'] = $thumbnailPath;
                
                Log::info('HELP VIDEO - Thumbnail uploaded successfully to Supabase', [
                    'path' => $thumbnailPath,
                ]);
            }

            Log::info('HELP VIDEO - Creating video record in database', $videoData);

            // Create the video record
            $video = HelpVideo::create($videoData);

            Log::info('HELP VIDEO - Video record created', ['id' => $video->id]);

            // Generate signed URLs
            $this->generateSignedUrlsForVideo($video);

            DB::commit();

            Log::info('HELP VIDEO - Upload completed successfully');

            return response()->json([
                'success' => true,
                'data' => new HelpVideoResource($video),
                'message' => 'Video uploaded successfully'
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            
            Log::error('HELP VIDEO UPLOAD FAILED', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
                'error' => config('app.debug') ? $th->getMessage() : null
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'category' => ['sometimes', 'string', Rule::in(self::CATEGORIES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $video = HelpVideo::findOrFail($id);
            
            DB::beginTransaction();

            $updateData = [];

            if (isset($validated['title'])) {
                $updateData['title'] = $validated['title'];
            }
            
            if (isset($validated['description'])) {
                $updateData['description'] = $validated['description'];
            }
            
            if (isset($validated['category'])) {
                $updateData['category'] = $validated['category'];
            }
            
            if (isset($validated['is_active'])) {
                $updateData['is_active'] = $validated['is_active'];
            }

            // Upload new thumbnail if provided
            if ($request->hasFile('thumbnail')) {
                // Delete old thumbnail if exists
                if ($video->thumbnail_url) {
                    try {
                        Storage::disk('supabase')->delete($video->getRawOriginal('thumbnail_url'));
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete old thumbnail: ' . $e->getMessage());
                    }
                }

                // Use simpler approach
                $thumbnailPath = $request->file('thumbnail')->store('help-videos', 'supabase');
                $updateData['thumbnail_url'] = $thumbnailPath;
            }

            // Update the video
            if (!empty($updateData)) {
                $video->update($updateData);
            }
            
            // Refresh to get updated data
            $video->refresh();

            // Generate signed URLs
            $this->generateSignedUrlsForVideo($video);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => new HelpVideoResource($video),
                'message' => 'Video updated successfully'
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            
            Log::error('UPDATING HELP VIDEO', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
                'error' => config('app.debug') ? $th->getMessage() : null
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $video = HelpVideo::findOrFail($id);
            
            DB::beginTransaction();

            // Get the raw file paths
            $videoPath = $video->getRawOriginal('video_url');
            $thumbnailPath = $video->getRawOriginal('thumbnail_url');

            // Delete video file from Supabase
            if ($videoPath) {
                try {
                    Storage::disk('supabase')->delete($videoPath);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete video file: ' . $e->getMessage());
                }
            }

            // Delete thumbnail file from Supabase
            if ($thumbnailPath) {
                try {
                    Storage::disk('supabase')->delete($thumbnailPath);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete thumbnail: ' . $e->getMessage());
                }
            }

            $video->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Video deleted successfully'
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            
            Log::error('DELETING HELP VIDEO', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function statistics()
    {
        try {
            $totalVideos = HelpVideo::count();
            $totalViews = HelpVideo::sum('view_count');
            
            $categories = HelpVideo::select('category')
                ->distinct()
                ->pluck('category')
                ->toArray();
            
            $mostViewed = HelpVideo::select('id', 'title', 'view_count')
                ->orderBy('view_count', 'desc')
                ->limit(5)
                ->get()
                ->toArray();
            
            $recentVideos = HelpVideo::select('id', 'title', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->toArray();

            $statistics = [
                'total_videos' => $totalVideos,
                'total_views' => $totalViews,
                'categories' => $categories,
                'most_viewed' => $mostViewed,
                'recent_videos' => $recentVideos,
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics,
                'message' => 'Statistics retrieved successfully'
            ]);
        } catch (\Throwable $th) {
            Log::error('FETCHING HELP VIDEO STATISTICS', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function incrementViews($id)
    {
        try {
            $video = HelpVideo::findOrFail($id);
            $video->increment('view_count');
            $video->refresh();
            
            // Generate signed URLs
            $this->generateSignedUrlsForVideo($video);
            
            return response()->json([
                'success' => true,
                'data' => new HelpVideoResource($video),
                'message' => 'View count incremented'
            ]);
        } catch (\Throwable $th) {
            Log::error('INCREMENTING VIEW COUNT', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function options()
    {
        try {
            $options = [
                'categories' => self::CATEGORIES,
            ];

            return response()->json([
                'success' => true,
                'data' => $options
            ]);
        } catch (\Throwable $th) {
            Log::error('HELP VIDEO OPTIONS ERROR', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get options'
            ], 500);
        }
    }

    /**
     * Helper method to generate signed URLs for a video
     */
    private function generateSignedUrlsForVideo(HelpVideo $video): void
    {
        try {
            // Generate video URL
            $rawVideoUrl = $video->getRawOriginal('video_url');
            if ($rawVideoUrl) {
                $video->video_url = Storage::disk('supabase')->temporaryUrl(
                    $rawVideoUrl,
                    now()->addMinutes(120)
                );
            }

            // Generate thumbnail URL
            $rawThumbnailUrl = $video->getRawOriginal('thumbnail_url');
            if ($rawThumbnailUrl) {
                $video->thumbnail_url = Storage::disk('supabase')->temporaryUrl(
                    $rawThumbnailUrl,
                    now()->addMinutes(60)
                );
            }
        } catch (\Exception $e) {
            Log::error('Error generating signed URLs: ' . $e->getMessage());
        }
    }
}