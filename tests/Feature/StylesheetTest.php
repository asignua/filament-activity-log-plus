<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\ActivityLogPlusServiceProvider;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\ActivityLogResource;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Gate;

class StylesheetTest extends TestCase
{
    public function test_the_compiled_stylesheet_is_shipped_and_registered(): void
    {
        $this->assertFileExists(__DIR__.'/../../resources/dist/filament-activity-log-plus.css');

        $href = FilamentAsset::getStyleHref(ActivityLogPlusServiceProvider::STYLESHEET, ActivityLogPlusServiceProvider::PACKAGE);

        $this->assertStringContainsString('filament-activity-log-plus', $href);
    }

    public function test_the_stylesheet_declares_the_layer_order(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../resources/dist/filament-activity-log-plus.css');

        $this->assertStringContainsString('@layer theme,base,components', $css);
        $this->assertStringNotContainsString('box-sizing', $css, 'no preflight');
    }

    public function test_the_panel_links_the_stylesheet_when_the_plugin_is_on(): void
    {
        $this->actingAs($this->admin());
        Gate::define(ActivityLogPlusPlugin::GATE_RESOURCE, fn (): bool => true);

        $html = $this->get(ActivityLogResource::getUrl('index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'rel="stylesheet" href="'.e(FilamentAsset::getStyleHref(ActivityLogPlusServiceProvider::STYLESHEET, ActivityLogPlusServiceProvider::PACKAGE)).'"',
            (string) $html,
        );
    }
}
