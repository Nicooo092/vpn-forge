<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Filament\Widgets\BandwidthChart;
use App\Models\BandwidthSample;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class BandwidthChartTest extends TestCase
{
    use RefreshDatabase;

    private function chartData(string $filter): array
    {
        $chart = new BandwidthChart;

        $filterProperty = new ReflectionProperty($chart, 'filter');
        $filterProperty->setValue($chart, $filter);

        $getData = new ReflectionMethod($chart, 'getData');

        return $getData->invoke($chart);
    }

    private function sample(int $bytesIn, int $bytesOut, int $minutesAgo = 30): void
    {
        $service = Service::firstOrCreate(
            ['interface_name' => 'wg0'],
            [
                'name' => 'test',
                'subnet_cidr' => '10.0.0.0/24',
                'listen_port' => 51820,
                'protocol' => VpnProtocol::WireGuard,
                'transport' => Transport::Udp,
                'status' => ServiceStatus::Active,
                'logging_enabled_default' => true,
                'config' => [],
            ],
        );

        BandwidthSample::create([
            'service_id' => $service->id,
            'service_user_id' => null,
            'sampled_at' => now()->subMinutes($minutesAgo),
            'bytes_in_delta' => $bytesIn,
            'bytes_out_delta' => $bytesOut,
        ]);
    }

    /**
     * A single sample used to produce a chart with one label and one point,
     * which Chart.js draws as a lone floating dot with no line.
     */
    public function test_every_bucket_in_the_window_is_present_even_without_samples(): void
    {
        $this->sample(bytesIn: 500 * 1024 * 1024, bytesOut: 100 * 1024 * 1024);

        $data = $this->chartData('24h');

        $this->assertCount(24, $data['labels']);
        $this->assertCount(24, $data['datasets'][0]['data']);
        $this->assertCount(24, $data['datasets'][1]['data']);

        // Exactly one populated bucket, the rest sitting at zero rather than
        // simply not existing.
        $nonZero = array_filter($data['datasets'][0]['data'], fn (float $v) => $v > 0);
        $this->assertCount(1, $nonZero);
        $this->assertSame(23, count(array_filter($data['datasets'][0]['data'], fn (float $v) => $v === 0.0)));
    }

    public function test_labels_are_short_enough_to_read(): void
    {
        $this->sample(1024, 1024);

        $labels = $this->chartData('24h')['labels'];

        // "18:00", not "2026-07-31 18:00".
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $labels[0]);
    }

    public function test_the_axis_unit_follows_the_size_of_the_traffic(): void
    {
        $this->sample(bytesIn: 3 * 1024 ** 3, bytesOut: 1024 ** 3);
        $gigabytes = $this->chartData('24h');
        $this->assertSame('Download (GB)', $gigabytes['datasets'][0]['label']);
        $this->assertContains(3.0, $gigabytes['datasets'][0]['data']);

        BandwidthSample::query()->delete();

        $this->sample(bytesIn: 40 * 1024, bytesOut: 8 * 1024);
        $kilobytes = $this->chartData('24h');
        $this->assertSame('Download (KB)', $kilobytes['datasets'][0]['label']);
        $this->assertContains(40.0, $kilobytes['datasets'][0]['data']);
    }

    public function test_a_month_of_data_is_bucketed_by_day(): void
    {
        $this->sample(1024 ** 2, 1024 ** 2, minutesAgo: 60 * 24 * 10);

        $data = $this->chartData('30d');

        $this->assertCount(30, $data['labels']);
        $this->assertSame(1, count(array_filter($data['datasets'][0]['data'], fn (float $v) => $v > 0)));
    }
}
