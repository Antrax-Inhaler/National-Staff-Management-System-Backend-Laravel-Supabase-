<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MemberDashboardController extends Controller
{
    public function index(Request $request)
    {
        try {
            $member = \App\Models\Member::with(['affiliate', 'user'])
                ->where('user_id', Auth::id())
                ->firstOrFail();

            // Profile Summary
            $profileSummary = $this->getProfileSummary($member);

            // Current Positions
            $currentPositions = $this->getCurrentPositions($member);

            // Organization Roles
            $nationalRoles = $this->getOrganizationRoles($member);

            // Upcoming Events
            $upcomingEvents = $this->getUpcomingEvents($member);

            // Attendance History
            $attendanceHistory = $this->getAttendanceHistory($member);

            // Communications
            $communications = $this->getCommunications($member);

            // Resources (Links)
            $resources = $this->getResources();

            // Documents
            $documents = $this->getDocuments($member);

            $dashboardData = [
                'profile_summary' => $profileSummary,
                'current_positions' => $currentPositions,
                'national_roles' => $nationalRoles,
                'upcoming_events' => $upcomingEvents,
                'attendance_history' => $attendanceHistory,
                'communications' => $communications,
                'resources' => $resources,
                'documents' => $documents,
            ];

            return response()->json([
                'success' => true,
                'data' => $dashboardData,
                'message' => 'Dashboard data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Member dashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getProfileSummary($member)
    {
        return [
            'name' => $member->first_name . ' ' . $member->last_name,
            'member_id' => $member->member_id,
            'affiliate' => $member->affiliate?->name,
            'employment_status' => $member->employment_status,
            'level' => $member->level,
            'status' => $member->status,
            'work_email' => $member->work_email,
            'work_phone' => $member->work_phone,
            'profile_completion' => $this->calculateProfileCompletion($member)
        ];
    }

    private function getCurrentPositions($member)
    {
        return \App\Models\AffiliateOfficer::with(['position', 'affiliate'])
            ->where('member_id', $member->id)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->where('is_vacant', false)
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(function ($officer) {
                return [
                    'position' => $officer->position->name,
                    'affiliate' => $officer->affiliate->name,
                    'start_date' => $officer->start_date,
                    'end_date' => $officer->end_date,
                ];
            });
    }

    private function getOrganizationRoles($member)
    {
        return \App\Models\MemberOrganizationRole::with('role')
            ->where('member_id', $member->id)
            ->get()
            ->map(function ($nationalRole) {
                return [
                    'role' => $nationalRole->role->name,
                    'description' => $nationalRole->role->description,
                ];
            });
    }

    private function getUpcomingEvents($member)
    {
        return \App\Models\EventAttendance::with('event')
            ->where('member_id', $member->id)
            ->whereHas('event', function ($query) {
                $query->where('start_date', '>=', now());
            })
            ->where('attendance_status', 'Registered')
            ->join('events', 'event_attendances.event_id', '=', 'events.id')
            ->orderBy('events.start_date', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($attendance) {
                return [
                    'event_id' => $attendance->event->id,
                    'title' => $attendance->event->title,
                    'start_date' => $attendance->event->start_date,
                    'end_date' => $attendance->event->end_date,
                    'location' => $attendance->event->location,
                    'attendance_status' => $attendance->attendance_status,
                    'registered_at' => $attendance->registered_at,
                ];
            });
    }
    private function getAttendanceHistory($member)
    {
        return \App\Models\EventAttendance::with('event')
            ->where('member_id', $member->id)
            ->whereHas('event', function ($query) {
                $query->where('start_date', '<', now());
            })
            ->join('events', 'event_attendances.event_id', '=', 'events.id')
            ->orderBy('events.start_date', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($attendance) {
                return [
                    'event_id' => $attendance->event->id,
                    'title' => $attendance->event->title,
                    'start_date' => $attendance->event->start_date,
                    'attendance_status' => $attendance->attendance_status,
                    'attended_at' => $attendance->attended_at,
                ];
            });
    }
    private function getCommunications($member)
    {
        if (!$member->affiliate_id) {
            return collect();
        }

        return \App\Models\CommunicationLog::where('affiliate_id', $member->affiliate_id)
            ->where('sent_at', '>=', now()->subDays(30)) // Last 30 days
            ->where('status', 'Sent')
            ->orderBy('sent_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($communication) {
                return [
                    'id' => $communication->id,
                    'subject' => $communication->subject,
                    'message' => substr($communication->message, 0, 100) . '...',
                    'communication_type' => $communication->communication_type,
                    'sent_at' => $communication->sent_at,
                    'sent_by' => $communication->sent_by,
                ];
            });
    }

    private function getResources()
    {
        return \App\Models\Link::where('is_active', true)
            ->orderBy('category')
            ->orderBy('display_order')
            ->get()
            ->groupBy('category')
            ->map(function ($links, $category) {
                return [
                    'category' => $category ?: 'General',
                    'links' => $links->map(function ($link) {
                        return [
                            'title' => $link->title,
                            'url' => $link->url,
                            'description' => $link->description,
                        ];
                    })
                ];
            })->values();
    }

    private function getDocuments($member)
    {
        $documents = collect();

        if ($member->affiliate_id) {
            // Contracts
            $contracts = \App\Models\Contract::where('affiliate_id', $member->affiliate_id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($contract) {
                    return [
                        'type' => 'contract',
                        'id' => $contract->id,
                        'title' => $contract->title,
                        'description' => $contract->description,
                        'file_name' => $contract->file_name,
                        'file_size' => $contract->file_size,
                        'uploaded_at' => $contract->uploaded_at,
                    ];
                });

            // Arbitrations
            $arbitrations = \App\Models\Arbitration::where('affiliate_id', $member->affiliate_id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($arbitration) {
                    return [
                        'type' => 'arbitration',
                        'id' => $arbitration->id,
                        'title' => $arbitration->title,
                        'description' => $arbitration->description,
                        'file_name' => $arbitration->file_name,
                        'file_size' => $arbitration->file_size,
                        'uploaded_at' => $arbitration->uploaded_at,
                    ];
                });

            $documents = $contracts->merge($arbitrations)->sortByDesc('uploaded_at')->take(5);
        }

        return $documents;
    }

    private function calculateProfileCompletion($member)
    {
        $fields = [
            'first_name' => !empty($member->first_name),
            'last_name' => !empty($member->last_name),
            'work_email' => !empty($member->work_email),
            'work_phone' => !empty($member->work_phone),
            'address_line1' => !empty($member->address_line1),
            'city' => !empty($member->city),
            'state' => !empty($member->state),
            'zip_code' => !empty($member->zip_code),
        ];

        $completed = count(array_filter($fields));
        $total = count($fields);

        return round(($completed / $total) * 100);
    }
}
