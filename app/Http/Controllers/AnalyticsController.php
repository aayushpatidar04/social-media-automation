<?php

// app/Http/Controllers/AnalyticsController.php
 
namespace App\Http\Controllers;
 
use App\Models\Organization;
use App\Services\AnalyticsService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
 
class AnalyticsController extends Controller
{
    public function dashboard(): Response
    {
        $organization = Auth::user()->organization;
        
        $analytics = new AnalyticsService($organization);
        $metrics = $analytics->getDashboardMetrics();
 
        return Inertia::render('Analytics', [
            'metrics' => $metrics,
            'organization' => $organization,
            'pusher_key' => env('PUSHER_APP_KEY'),
            'pusher_cluster' => env('PUSHER_APP_CLUSTER'),
        ]);
    }
 
    public function getMetrics(string $metricType)
    {
        $organization = Auth::user()->organization;
        $analytics = new AnalyticsService($organization);
 
        $metrics = [
            'summary' => $analytics->getSummaryMetrics(),
            'by_platform' => $analytics->getCommentsByPlatform(),
            'sentiment' => $analytics->getSentimentDistribution(),
            'intent' => $analytics->getIntentDistribution(),
            'leads' => $analytics->getLeadMetrics(),
            'timeline' => $analytics->getActivityTimeline(),
            'top_authors' => $analytics->getTopAuthors(),
        ];
 
        if (isset($metrics[$metricType])) {
            return response()->json($metrics[$metricType]);
        }
 
        return response()->json(['error' => 'Invalid metric type'], 400);
    }
 
    public function exportReport(Request $request)
    {
        $request->validate([
            'format' => 'required|in:csv,pdf',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);
 
        $organization = Auth::user()->organization;
        $analytics = new AnalyticsService($organization);
 
        // Get data for date range
        $comments = $organization->socialComments()
            ->whereBetween('commented_at', [$request->date_from, $request->date_to])
            ->get();
 
        if ($request->format === 'csv') {
            return $this->exportAsCSV($comments);
        }
 
        return $this->exportAsPDF($comments);
    }
 
    private function exportAsCSV($comments)
    {
        $filename = 'analytics_' . now()->format('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];
 
        $callback = function () use ($comments) {
            $file = fopen('php://output', 'w');
            
            // CSV header
            fputcsv($file, [
                'Date', 'Platform', 'Author', 'Comment', 'Sentiment',
                'Intent', 'Lead Score', 'Status'
            ]);
 
            // CSV rows
            foreach ($comments as $comment) {
                fputcsv($file, [
                    $comment->commented_at->format('Y-m-d H:i:s'),
                    $comment->socialAccount->platform,
                    $comment->author_name,
                    substr($comment->content, 0, 100),
                    $comment->sentiment,
                    $comment->intent,
                    $comment->lead_score,
                    $comment->status,
                ]);
            }
 
            fclose($file);
        };
 
        return response()->stream($callback, 200, $headers);
    }
 
    private function exportAsPDF($comments)
    {
        // TODO: Implement PDF export using mPDF
        // For now, return CSV as fallback
        return $this->exportAsCSV($comments);
    }
}
