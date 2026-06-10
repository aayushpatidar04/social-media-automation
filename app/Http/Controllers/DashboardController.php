<?php

// app/Http/Controllers/DashboardController.php
 
namespace App\Http\Controllers;
 
use App\Models\Organization;
use App\Services\AnalyticsService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
 
class DashboardController extends Controller
{
    public function index(): Response
    {
        $organization = Auth::user()->organization;
        
        $analytics = new AnalyticsService($organization);
        $metrics = $analytics->getDashboardMetrics();
 
        return Inertia::render('Dashboard', [
            'organization' => $organization,
            'metrics' => $metrics,
            'pusher_key' => env('PUSHER_APP_KEY'),
            'pusher_cluster' => env('PUSHER_APP_CLUSTER'),
        ]);
    }
}
