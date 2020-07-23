<?php

namespace Apoplavs\Support\AutoDoc\Http\Middleware;

use Closure;
use Apoplavs\Support\AutoDoc\Services\SwaggerService;

/**
 * @property SwaggerService $service
 */
class AutoDocMiddleware
{
    /** @var \Illuminate\Contracts\Foundation\Application|mixed  */
    protected $service;

    /** @var bool flag if need to run this test */
    public static $skipped = false;

    public function __construct()
    {
        $this->service = app(SwaggerService::class);
    }

    /**
     * @param $request
     * @param \Closure $next
     * @return mixed
     * @throws \Apoplavs\Support\AutoDoc\Exceptions\WrongSecurityConfigException
     * @throws \ReflectionException
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (!config('auto-doc.enabled', false)) {
            self::$skipped = true;
        }

        if ((config('app.env') == 'testing') && !self::$skipped) {
            $this->service->addData($request, $response);
        }

        return $response;
    }
}
