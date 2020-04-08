<?php

namespace Apoplavs\Support\AutoDoc\Services;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Minime\Annotations\Interfaces\AnnotationsBagInterface;
use Minime\Annotations\Reader as AnnotationReader;
use Minime\Annotations\Parser;
use Minime\Annotations\Cache\ArrayCache;
use Apoplavs\Support\AutoDoc\Interfaces\DataCollectorInterface;
use Apoplavs\Support\AutoDoc\Traits\GetDependenciesTrait;
use Apoplavs\Support\AutoDoc\Exceptions\WrongSecurityConfigException;
use Apoplavs\Support\AutoDoc\Exceptions\DataCollectorClassNotFoundException;
use Apoplavs\Support\AutoDoc\DataCollectors\JsonDataCollector;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Testing\File;

/**
 * @property DataCollectorInterface $dataCollector
 */
class SwaggerService
{
    use GetDependenciesTrait;

    const ALLOWED_SECURITY = ['bearerAuth', 'basicAuth', 'ApiKeyAuth'];

    protected $annotationReader;
    protected $dataCollector;

    protected $data;
    protected $container;
    private $uri;
    private $method;
    /**
     * @var \Illuminate\Http\Request
     */
    private $request;
    private $item;
    private $security;

    public function __construct(Container $container)
    {
        $this->setDataCollector();

        if (config('app.env') == 'testing') {
            $this->container = $container;

            $this->annotationReader = new AnnotationReader(new Parser, new ArrayCache);;

            $this->security = config('auto-doc.security', '');

            $this->data = $this->dataCollector->getTmpData();

            if (empty($this->data)) {
                $this->data = $this->generateEmptyData();

                $this->dataCollector->saveTmpData($this->data);
            }
        }
    }

    /**
     * @throws \Apoplavs\Support\AutoDoc\Exceptions\DataCollectorClassNotFoundException
     */
    protected function setDataCollector()
    {
        $dataCollectorClass = config('auto-doc.data_collector');

        if (empty($dataCollectorClass)) {
            $this->dataCollector = app(JsonDataCollector::class);
        } elseif (!class_exists($dataCollectorClass)) {
            throw new DataCollectorClassNotFoundException();
        } else {
            $this->dataCollector = app($dataCollectorClass);
        }
    }

    /**
     * @return array
     * @throws \Apoplavs\Support\AutoDoc\Exceptions\WrongSecurityConfigException
     * @throws \Throwable
     */
    protected function generateEmptyData(): array
    {
        $data = [
            'openapi'    => config('auto-doc.openapi.version'),
            'servers'    => $this->getServers(),
            'components' => []
        ];

        $info = $this->prepareInfo(config('auto-doc.info'));
        if (!empty($info)) {
            $data['info'] = $info;
        }

        $defaultResponses = $this->prepareDefaultResponse(config('auto-doc.defaults.code-descriptions'));
        if (!empty($defaultResponses)) {
            $data['components']['responses'] = $defaultResponses;
        }

        $securitySchemes = $this->generateSecurityDefinition();

        if (!empty($securitySchemes)) {
            $data['components']['securitySchemes'] = $securitySchemes;
        }

        $data['info']['description'] = view($data['info']['description'])->render();

        return $data;
    }

    /**
     * Merge this app URL with servers from config
     *
     * @return array
     */
    protected function getServers(): array
    {
        $currentServer = [
            "url"         => config('app.url'),
            "description" => 'This app server URL'
        ];

        return array_merge([$currentServer], config('auto-doc.servers'));
    }

    /**
     * Set Security definition in general setting
     * @return array|string
     * @throws \Apoplavs\Support\AutoDoc\Exceptions\WrongSecurityConfigException
     */
    protected function generateSecurityDefinition()
    {
        $security = $this->security;

        if (empty($security)) {
            return '';
        }

        if (!in_array($security, self::ALLOWED_SECURITY)) {
            throw new WrongSecurityConfigException($security);
        }

        switch ($security) {
            case 'bearerAuth':
                $securityConfig = [
                    'description'  => 'The authorization token, usually represented as: Bearer ...',
                    'type'         => 'http',
                    'scheme'       => 'bearer',
                    'bearerFormat' => 'JWT'
                ];
                break;
            case 'basicAuth':
                $securityConfig = [
                    'description' => 'Base64-encoded string username:password',
                    'type'        => 'http',
                    'scheme'      => 'basic'
                ];
                break;
            case 'ApiKeyAuth':
                $securityConfig = [
                    'description' => 'Header X-API-KEY with your value',
                    'type'        => 'apiKey',
                    'in'          => 'header',
                    'name'        => 'X-API-KEY'
                ];
                break;
            default:
                $securityConfig = [];
        }

        return [
            $security => $securityConfig
        ];
    }

    /**
     * Main function to add data into
     * doc.json file
     * @param \Illuminate\Http\Request $request
     * @param $response
     * @throws \ReflectionException
     * @throws \Apoplavs\Support\AutoDoc\Exceptions\WrongSecurityConfigException
     */
    public function addData(Request $request, $response)
    {
        $this->request = $request;

        $this->prepareItem();

        $this->parseRequest();
        $this->parseResponse($response);

        $this->dataCollector->saveTmpData($this->data);
    }

    protected function prepareItem()
    {
        $this->uri = $this->getUri();
        $this->method = strtolower($this->request->getMethod());

        if (empty(Arr::get($this->data, "paths.{$this->uri}.{$this->method}"))) {
            $this->data['paths'][$this->uri][$this->method] = [
                'tags'        => [],
                'content'     => [],
                'parameters'  => $this->getPathParams(),
                'responses'   => [],
                'security'    => [],
                'description' => ''
            ];
        }

        $this->item = &$this->data['paths'][$this->uri][$this->method];
    }

    protected function getUri()
    {
        $uri = $this->request->route()->uri();
        $basePath = str_replace('/', '', config('auto-doc.basePath'));
        $preparedUri = preg_replace("/^{$basePath}/", '', $uri);

        return str_replace('//', '/', '/' . $basePath . '/' . $preparedUri);
    }

    protected function getPathParams()
    {
        $params = [];

        preg_match_all('/{.*?}/', $this->uri, $params);

        $params = Arr::collapse($params);

        $result = [];

        foreach ($params as $param) {
            $key = preg_replace('/[{}]/', '', $param);

            $result[] = [
                'in'          => 'path',
                'name'        => $key,
                'description' => '',
                'required'    => true,
                'type'        => 'string'
            ];
        }

        return $result;
    }

    /**
     * @throws \ReflectionException
     * @throws \Apoplavs\Support\AutoDoc\Exceptions\WrongSecurityConfigException
     */
    protected function parseRequest()
    {
        $this->saveContentType();
        $this->saveTags();

        $concreteRequest = $this->getConcreteRequest();

        if (empty($concreteRequest)) {
            $this->item['description'] = '';
            $this->saveSecurity(null);

            return;
        }

        $annotations = $this->annotationReader->getClassAnnotations($concreteRequest);

        $this->saveSecurity($annotations->get('security'));
        $this->saveParameters($concreteRequest, $annotations);
        $this->saveSummary($concreteRequest, $annotations);
        $this->saveDescription($annotations);
    }

    protected function parseResponse($response)
    {
        $contentTypeList = $this->data['paths'][$this->uri][$this->method]['content'];

        $contentType = $response->headers->get('Content-type');
        if (is_null($contentType)) {
            $contentType = 'application/json';
        }

        if (!in_array($contentType, $contentTypeList)) {
            $this->item['content'][] = $contentType;
        }

        $responses = $this->item['responses'];
        $code = $response->getStatusCode();

        if (!in_array($code, $responses)) {
            $this->saveExample(
                $code,
                $response->getContent(),
                $contentType
            );
        }
    }

    protected function saveExample($code, $content, $contentType)
    {
        $description = $this->getResponseDescription($code);

        $availableContentTypes = [
            'application',
            'text'
        ];
        $explodedContentType = explode('/', $contentType);

        if (in_array($explodedContentType[0], $availableContentTypes)) {
            $this->item['responses'][$code] = $this->makeResponseExample($content, $contentType, $description);
        } else {
            $this->item['responses'][$code] = '*Unavailable for preview*';
        }
    }

    /**
     * @param $content
     * @param string $mimeType
     * @param string $description
     * @return array
     */
    protected function makeResponseExample($content, string $mimeType = 'application/json', $description = ''): array
    {
        $responseExample = [
            'description' => $description
        ];

        if ($mimeType === 'application/json') {
            $responseExample['content']['application/json']['schema'] = [
                'type'    => 'object',
                'example' => json_decode($content, true),
            ];
        } else {
            $responseExample['content']['text/plain']['schema'] = [
                'type'    => 'string',
                'example' => $content,
            ];
        }

        return $responseExample;
    }

    protected function saveParameters($request, AnnotationsBagInterface $annotations)
    {
        $formRequest = new $request;
        $formRequest->setUserResolver($this->request->getUserResolver());
        $formRequest->setRouteResolver($this->request->getRouteResolver());
        $rules = method_exists($formRequest, 'rules') ? $formRequest->rules() : [];

        $actionName = $this->getActionName($this->uri);

        $this->addDefaultHeaders();

        if (in_array($this->method, ['get', 'delete'])) {
            $this->saveGetRequestParameters($rules, $annotations);
        } else {
            $this->savePostRequestParameters($actionName, $rules, $annotations);
        }
    }

    protected function addDefaultHeaders()
    {
        $defaultHeaders = config('auto-doc.defaults.headers');

        foreach ($defaultHeaders as $headerName => $headerValue) {
            $this->item['parameters'][] = [
                'in'          => 'header',
                'name'        => $headerName,
                'description' => '',
                'required'    => true,
                'schema'      => [
                    "default" => $headerValue
                ]
            ];
        }
    }

    protected function saveGetRequestParameters($rules, AnnotationsBagInterface $annotations)
    {
        foreach ($rules as $parameter => $rule) {
            $validation = explode('|', $rule);

            $description = $annotations->get($parameter, implode(', ', $validation));

            $existedParameter = Arr::first($this->item['parameters'],
                function ($existedParameter, $key) use ($parameter) {
                    return $existedParameter['name'] == $parameter;
                });

            if (empty($existedParameter)) {
                $parameterDefinition = [
                    'in'          => 'query',
                    'name'        => $parameter,
                    'description' => $description,
                    'type'        => $this->getParameterType($validation)
                ];
                if (in_array('required', $validation)) {
                    $parameterDefinition['required'] = true;
                }

                $this->item['parameters'][] = $parameterDefinition;
            }
        }
    }

    protected function savePostRequestParameters($actionName, $rules, AnnotationsBagInterface $annotations)
    {
        if ($this->requestHasMoreProperties($actionName)) {
            if ($this->requestHasBody()) {
                $this->item['parameters'][] = [
                    'in'          => 'body',
                    'name'        => 'body',
                    'description' => '',
                    'required'    => true,
                    'schema'      => [
                        "\$ref" => "#/components/parameters/{$actionName}Object"
                    ]
                ];
            }

            $this->saveDefinitions($actionName, $rules, $annotations);
        }
    }

    protected function saveDefinitions($objectName, $rules, $annotations)
    {
        $data = [
            'type'       => 'object',
            'properties' => []
        ];
        foreach ($rules as $parameter => $rule) {
            if (is_array($rule)) {
                $rule = $this->convertArrToString($rule);
            }

            $rulesArray = explode('|', $rule);
            $parameterType = $this->getParameterType($rulesArray);
            $this->saveParameterType($data, $parameter, $parameterType);
            $this->saveParameterDescription($data, $parameter, $rulesArray, $annotations);

            if (in_array('required', $rulesArray)) {
                $data['required'][] = $parameter;
            }
        }

        $data['example'] = $this->generateExample($data['properties']);
        $this->data['components']['parameters'][$objectName . 'Object'] = $data;
    }

    protected function convertArrToString(array $rules = []): string
    {
        $strParams = '';

        foreach ($rules as $key => $rule) {
            if (is_string($rule)) {
                $strParams .= $rule . '|';
            }
        }

        return substr($strParams, 0, -1);
    }

    protected function getParameterType(array $validation)
    {
        $validationRules = [
            'array'   => 'object',
            'boolean' => 'boolean',
            'date'    => 'date',
            'digits'  => 'integer',
            'email'   => 'string',
            'integer' => 'integer',
            'numeric' => 'double',
            'string'  => 'string'
        ];

        $parameterType = 'string';

        foreach ($validation as $item) {
            if (in_array($item, array_keys($validationRules))) {
                $parameterType = $validationRules[$item];
                break;
            }
        }

        return $parameterType;
    }

    protected function saveParameterType(&$data, $parameter, $parameterType)
    {
        $data['properties'][$parameter] = [
            'type' => $parameterType,
        ];
    }

    protected function saveParameterDescription(
        &$data,
        $parameter,
        array $rulesArray,
        AnnotationsBagInterface $annotations
    ) {
        $description = $annotations->get($parameter, implode(', ', $rulesArray));
        $data['properties'][$parameter]['description'] = $description;
    }

    protected function requestHasMoreProperties($actionName)
    {
        $requestParametersCount = count($this->request->all());

        if (isset($this->data['definitions'][$actionName . 'Object']['properties'])) {
            $objectParametersCount = count($this->data['definitions'][$actionName . 'Object']['properties']);
        } else {
            $objectParametersCount = 0;
        }

        return $requestParametersCount > $objectParametersCount;
    }

    protected function requestHasBody()
    {
        $parameters = $this->data['paths'][$this->uri][$this->method]['parameters'];

        $bodyParamExisted = Arr::where($parameters, function ($value, $key) {
            return $value['name'] == 'body';
        });

        return empty($bodyParamExisted);
    }

    /**
     * Get concrete class of Request
     * witch take the function
     * @return mixed|null
     */
    public function getConcreteRequest()
    {
        $controller = $this->request->route()->getActionName();

        if ($controller == 'Closure') {
            return null;
        }

        $explodedController = explode('@', $controller);

        $class = $explodedController[0];
        $method = $explodedController[1];

        $instance = app($class);
        $route = $this->request->route();

        $parameters = $this->resolveClassMethodDependencies(
            $route->parametersWithoutNulls(), $instance, $method
        );

        return Arr::first($parameters, function ($key, $parameter) {
            return preg_match('/Request/', $key);
        });
    }

    /**
     * Save the Content-type in request
     */
    public function saveContentType()
    {
        switch ($this->method) {
            case 'POST':
            case 'post':
                $contentType = 'application/json';
                break;
            case 'PUT':
            case 'put':
                $contentType = 'application/x-www-form-urlencoded';
                break;
            default:
                $contentType = $this->request->header('Content-Type');
        }

        if (!empty($contentType)) {
            $this->item['requestBody']['content'] = [
                $contentType => [
                    'schema' => [
                        'type' => 'object'
                    ]
                ]
            ];
        }
    }

    /**
     * Get second (or first if second does not exists) parameter from URL name
     * and save it as tags
     * swagger separates requests by tags
     */
    public function saveTags()
    {
        $explodedUri = explode('/', $this->uri);

        if (Arr::exists($explodedUri, 3)) {
            $this->item['tags'][] = Arr::get($explodedUri, 3);
        } elseif (Arr::exists($explodedUri, 2)) {
            $this->item['tags'][] = Arr::get($explodedUri, 2);
        } else {
            $this->item['tags'][] = Arr::get($explodedUri, 1);
        }
    }

    /**
     * Save the description of the request
     *
     * @param \Minime\Annotations\Interfaces\AnnotationsBagInterface $annotations
     */
    public function saveDescription(AnnotationsBagInterface $annotations)
    {
        $description = $annotations->get('description');

        if (!empty($description)) {
            $this->item['description'] = $description;
        } else {
            $this->item['description'] = '';
        }
    }

    /**
     * @param $security
     * @throws \Apoplavs\Support\AutoDoc\Exceptions\WrongSecurityConfigException
     */
    protected function saveSecurity($security)
    {
        if (is_string($security)) {
            if (!in_array($security, self::ALLOWED_SECURITY)) {
                throw new WrongSecurityConfigException($security);
            }
            $this->addSecurityToOperation($security);

        } elseif ($security === false) {
            return;
        } elseif (!empty($this->security)) {
            $this->addSecurityToOperation($this->security);
        }
    }

    protected function addSecurityToOperation(string $securityType = '')
    {
        if ($this->data['paths'][$this->uri][$this->method]['security']) {
            foreach ($this->data['paths'][$this->uri][$this->method]['security'] as $secType => $val) {
                if ($secType == $securityType) {
                    return;
                }
            }
        }

        $security = &$this->data['paths'][$this->uri][$this->method]['security'];
        if (!empty($securityType)) {
            $security[] = [
                "{$securityType}" => []
            ];
        }
    }

    /**
     * Save the summary of the endpoint
     *
     * @param $request
     * @param \Minime\Annotations\Interfaces\AnnotationsBagInterface $annotations
     */
    protected function saveSummary($request, AnnotationsBagInterface $annotations)
    {
        $summary = $annotations->get('summary');

        if (empty($summary)) {
            $summary = $this->parseRequestName($request);
        }

        $this->item['summary'] = $summary;
    }

    protected function parseRequestName($request)
    {
        $explodedRequest = explode('\\', $request);
        $requestName = array_pop($explodedRequest);

        $underscoreRequestName = $this->camelCaseToUnderScore($requestName);

        return preg_replace('/[_]/', ' ', $underscoreRequestName);
    }

    protected function getResponseDescription($code)
    {
        $request = $this->getConcreteRequest();

        if (!empty($request) && is_string($this->annotationReader->getClassAnnotations($request)->get("_{$code}"))) {
            return $this->annotationReader->getClassAnnotations($request)->get("_{$code}");

        } elseif (config("auto-doc.defaults.code-descriptions.{$code}")) {
            return config("auto-doc.defaults.code-descriptions.{$code}");

        } elseif (Response::$statusTexts[$code]) {
            return Response::$statusTexts[$code];
        }

        return null;
    }

    protected function getActionName($uri)
    {
        $uriArr = explode('/', $uri);
        $basePath = str_replace('/', '', config('auto-doc.basePath'));
        $action = '';

        foreach ($uriArr as $partUri) {
            if ($partUri == $basePath) {
                continue;
            }
            $action .= Str::ucfirst(Str::camel($partUri));
        }

        return $action;
    }

    protected function saveTempData()
    {
        $exportFile = config('auto-doc.files.temporary');
        $data = json_encode($this->data);

        file_put_contents($exportFile, $data);
    }

    public function saveProductionData()
    {
        $this->dataCollector->saveData();
    }

    public function getDocFileContent()
    {
        return $this->dataCollector->getDocumentation();
    }

    private function camelCaseToUnderScore($input)
    {
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
        $ret = $matches[0];
        foreach ($ret as &$match) {
            $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
        }

        return implode('_', $ret);
    }

    protected function generateExample($properties)
    {
        $parameters = $this->replaceObjectValues($this->request->all());
        $example = [];

        $this->replaceNullValues($parameters, $properties, $example);

        return $example;
    }

    protected function replaceObjectValues($parameters)
    {
        $classNamesValues = [
            File::class => '[uploaded_file]',
        ];

        $parameters = Arr::dot($parameters);
        $returnParameters = [];

        foreach ($parameters as $parameter => $value) {
            if (is_object($value)) {
                $class = get_class($value);

                $value = Arr::get($classNamesValues, $class, $class);
            }

            Arr::set($returnParameters, $parameter, $value);
        }

        return $returnParameters;
    }

    /**
     * NOTE: All functions below are temporary solution for
     * this issue: https://github.com/OAI/OpenAPI-Specification/issues/229
     * We hope swagger developers will resolve this problem in next release of Swagger OpenAPI
     * */

    private function replaceNullValues($parameters, $types, &$example)
    {
        foreach ($parameters as $parameter => $value) {
            if (is_null($value) && in_array($parameter, $types)) {
                $example[$parameter] = $this->getDefaultValueByType($types[$parameter]['type']);
            } elseif (is_array($value)) {
                $this->replaceNullValues($value, $types, $example[$parameter]);
            } else {
                $example[$parameter] = $value;
            }
        }
    }

    private function getDefaultValueByType($type)
    {
        $values = [
            'object'  => 'null',
            'boolean' => false,
            'date'    => "0000-00-00",
            'integer' => 0,
            'string'  => '',
            'double'  => 0
        ];

        return $values[$type];
    }

    /**
     * @param $info
     * @return mixed
     */
    protected function prepareInfo($info)
    {
        if (empty($info)) {
            return $info;
        }

        foreach ($info['license'] as $key => $value) {
            if (empty($value)) {
                unset($info['license'][$key]);
            }
        }
        if (empty($info['license'])) {
            unset($info['license']);
        }

        return $info;
    }

    /**
     * @param $responses
     * @return mixed
     */
    protected function prepareDefaultResponse($responses): array
    {
        $defaultResponses = [];

        if (empty($responses)) {
            return [];
        }

        foreach ($responses as $code => $description) {
            $defaultResponses[$code]['description'] = $description;
        }

        return $defaultResponses;
    }

    protected function throwTraitMissingException()
    {
        $message = "ERROR:\n" .
            "It looks like you did not add AutoDocRequestTrait to your requester. \n" .
            "Please add it or mark in the test that you do not want to collect the \n" .
            "documentation for this case using the skipDocumentationCollecting() method\n";

        fwrite(STDERR, print_r($message, true));

        die;
    }
}
