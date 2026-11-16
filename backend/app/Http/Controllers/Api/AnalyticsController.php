<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private Analytics $analytics) {}

    public function overview()
    {
        return $this->analytics->overview($this->user());
    }

    public function revenue(Request $request)
    {
        abort_unless($this->user()->isAdmin(), 403);

        return $this->analytics->revenue($this->user(), (int) $request->input('months', 12));
    }

    public function attendance()
    {
        return $this->analytics->attendance($this->user());
    }

    public function exams()
    {
        return $this->analytics->exams($this->user());
    }

    public function atRisk()
    {
        return $this->analytics->atRisk($this->user());
    }
}
