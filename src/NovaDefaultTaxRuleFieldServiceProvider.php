<?php

namespace Ampeco\NovaDefaultTaxRuleField;

use Laravel\Nova\Nova;
use Laravel\Nova\Events\ServingNova;
use Illuminate\Support\ServiceProvider;
use Outl1ne\NovaTranslationsLoader\LoadsNovaTranslations;

class NovaDefaultTaxRuleFieldServiceProvider extends ServiceProvider
{
    use LoadsNovaTranslations;

    public function boot()
    {
        Nova::serving(function (ServingNova $event) {
            Nova::script('default-tax-rule-field', __DIR__ . '/../dist/js/entry.js');
            Nova::style('default-tax-rule-field', __DIR__ . '/../dist/css/entry.css');
        });

        $this->loadTranslations(__DIR__ . '/../lang', 'nova-default-tax-rule-field', true);
    }

    public function register()
    {
        //
    }
}
