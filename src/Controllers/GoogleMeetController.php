<?php

namespace Modules\GoogleMeet\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\GoogleMeet\Services\GoogleMeetService;

class GoogleMeetController extends Controller
{
    public function testConnection(Request $request)
    {
        $service = new GoogleMeetService();
        
        // Pass the request credentials directly so we can test before saving
        $success = $service->testConnection(
            $request->input('GOOGLE_PROJECT_ID'),
            $request->input('GOOGLE_CLIENT_ID'),
            $request->input('GOOGLE_CLIENT_SECRET'),
            $request->input('GOOGLE_SERVICE_ACCOUNT_JSON')
        );

        if ($success) {
            return response()->json([
                'status' => 'success',
                'message' => 'Successfully connected to Google Workspace/Calendar API.'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to connect. Please check your credentials and ensure the Google Calendar API is enabled.'
        ], 400);
    }
}
