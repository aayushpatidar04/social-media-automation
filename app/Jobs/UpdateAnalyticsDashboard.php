<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\AnalyticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateAnalyticsDashboard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    private Organization $organization;
    private string $metricType;

    public function __construct(Organization $organization, string $metricType = 'all')
    {
        $this->organization = $organization;
        $this->metricType = $metricType;
    }

    public function handle(): void
    {
        $analytics = new AnalyticsService($this->organization);
        $analytics->clearCache();

        if ($this->metricType === 'all') {
            $metrics = [
                'summary',
                'by_platform',
                'sentiment',
                'intent',
                'leads',
                'timeline'
            ];
            foreach ($metrics as $metric) {
                $analytics->updateMetric($metric);
            }
        } else {
            $analytics->updateMetric($this->metricType);
        }
    }
}