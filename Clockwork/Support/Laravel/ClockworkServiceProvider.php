<?php namespace Clockwork\Support\Laravel;

use Clockwork\Clockwork;
use Clockwork\DataSource\PhpDataSource;
use Clockwork\DataSource\LaravelDataSource;
use Clockwork\DataSource\EloquentDataSource;
use Clockwork\DataSource\SwiftDataSource;
use Clockwork\Storage\FileStorage;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ClockworkServiceProvider extends ServiceProvider
{
	public function boot()
	{
		$this->package('itsgoingd/clockwork', 'clockwork', __DIR__);

		if (!$this->app['clockwork.support']->isCollectingData()) {
			return; // Don't bother registering event listeners as we are not collecting data
		}

		$this->app['clockwork.laravel']->listenToEvents();
		$this->app['clockwork.eloquent']->listenToEvents();
		$this->app->make('clockwork.swift');

		if (!$this->app['clockwork.support']->isEnabled()) {
			return; // Clockwork is disabled, don't register the route
		}

		$app = $this->app;
		$this->app['router']->get('/__clockwork/{id}', function($id = null, $last = null) use($app)
		{
			return $this->app['clockwork.support']->getData($id, $last);
		})->where('id', '[0-9\.]+');
	}

	public function register()
	{
		$this->app->singleton('clockwork.support', function($app)
		{
			return new ClockworkSupport($app);
		});

		$this->app->singleton('clockwork.laravel', function($app)
		{
			return new LaravelDataSource($app);
		});

		$this->app->singleton('clockwork.swift', function($app)
		{
			return new SwiftDataSource($app['mailer']->getSwiftMailer());
		});

		$this->app->singleton('clockwork.eloquent', function($app)
        {
            return new EloquentDataSource($app['db'], $app['events']);
        });

		$this->app->singleton('clockwork', function($app)
		{
			$clockwork = new Clockwork();

			$clockwork
				->addDataSource(new PhpDataSource())
				->addDataSource($app['clockwork.laravel'])
				->addDataSource($app['clockwork.swift']);

			$filter = $app['config']->get('clockwork::config.filter', array());

			if ($app['config']->get('database.default') && !in_array('databaseQueries', $filter)) {
				$clockwork->addDataSource($app['clockwork.eloquent']);
			}

			$storage = new FileStorage($app['path.storage'] . '/clockwork');
			$storage->filter = $filter;

			$clockwork->setStorage($storage);

			return $clockwork;
		});

		$this->registerCommands();

		if ($this->isLegacyLaravel()) {
			$this->app->middleware('Clockwork\Support\Laravel\ClockworkLegacyMiddleware', array($this->app));
		} else if ($this->isOldLaravel()) {
			$app = $this->app;
			$this->app['router']->after(function($request, $response) use($app)
			{
				return $app['clockwork.support']->process($request, $response);
			});
		}
	}

	/**
	 * Register the artisan commands.
	 */
	public function registerCommands()
	{
		// Clean command
		$this->app['command.clockwork.clean'] = $this->app->share(function($app){
			return new ClockworkCleanCommand();
		});

		$this->commands(
			'command.clockwork.clean'
		);
	}

	public function provides()
	{
		return array('clockwork');
	}

	public function isLegacyLaravel()
	{
		return Str::startsWith(Application::VERSION, array('4.1.', '4.2.'));
	}

	public function isOldLaravel()
	{
		return Str::startsWith(Application::VERSION, '4.0.');
	}
}
