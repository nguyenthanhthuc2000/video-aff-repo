<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Script\ClaudeScriptWriter;
use App\Services\Script\FakeScriptWriter;
use App\Services\Script\ScriptWriter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ScriptWriter::class, function () {
            if (config('videoaff.script_driver') !== 'claude') {
                return new FakeScriptWriter;
            }

            return new ClaudeScriptWriter(
                new Client(apiKey: config('videoaff.claude.api_key')),
                config('videoaff.claude.model'),
                config('videoaff.claude.max_tokens'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
