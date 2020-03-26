<?php

namespace Apoplavs\Support\AutoDoc\Tests;

use Apoplavs\Support\AutoDoc\Services\SwaggerService;
use Apoplavs\Support\AutoDoc\Http\Middleware\AutoDocMiddleware;

trait AutoDocTestCaseTrait
{
    protected $docService;

    public function setUp(): void
    {
        parent::setUp();

        $this->docService = app(SwaggerService::class);
    }

    public function createApplication()
    {
        parent::createApplication();
    }

    public function tearDown(): void
    {
        $currentTestCount = $this->getTestResultObject()->count();
        $allTestCount = $this->getTestResultObject()->topTestSuite()->count();

        if (($currentTestCount == $allTestCount) && (!$this->hasFailed())) {
            $this->docService->saveProductionData();
        }

        parent::tearDown();
    }

    /**
     * Disabling documentation collecting on current test
     */
    public function skipDocumentationCollecting()
    {
        AutoDocMiddleware::$skipped = true;
    }
}
