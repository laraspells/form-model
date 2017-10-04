<?php

namespace LaraSpells\FormModel;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use UnexpectedValueException;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $defaultForm = config('form-model.default_form');
        $form = config('form-model.forms.'.$defaultForm);

        if (!$form) {
            throw new UnexpectedValueException("Form '{$defaultForm}' is not available in forms configuration.");
        }

        FormModel::setDefaultView($form['form']);
        FormModel::setDefaultInputViews($form['inputs']);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->setupConfig();
        $this->setupView();

    }

    protected function setupConfig()
    {
        $packageConfigPath = __DIR__.'/config/form-model.php';
        $appConfigPath = config_path('form-model.php');

        $this->publishes([
            $packageConfigPath => $appConfigPath,
        ], 'config');

        $this->mergeConfigFrom($packageConfigPath, 'form-model');    
    }

    protected function setupView()
    {
        $packageViewPath = __DIR__.'/resources/views';
        $appViewPath = resource_path('views/vendor/form-model');

        $this->loadViewsFrom([$packageViewPath, $appViewPath], 'form-model');
        $this->publishes([
            $packageViewPath => $appViewPath,
        ], 'views');
    }
}
