<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ActivityLogsController extends Controller
{
    public function getActivityLogs()
    {
        return response()->json([]);
    }
}
