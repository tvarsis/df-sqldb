<?php
namespace DreamFactory\Core\SqlDb\Resources;

use DreamFactory\Core\Events\ResourcePostProcess;
use DreamFactory\Core\Events\ResourcePreProcess;
use DreamFactory\Core\Models\Service;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Resources\BaseDbResource;
use DreamFactory\Core\SqlDb\Components\SqlDbResource;
use DreamFactory\Core\Utility\DbUtilities;
use DreamFactory\Library\Utility\Inflector;

class StoredProcedure extends BaseDbResource
{
    //*************************************************************************
    //	Traits
    //*************************************************************************

    use SqlDbResource;

    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with table schema
     */
    const RESOURCE_NAME = '_proc';

    //*************************************************************************
    //	Members
    //*************************************************************************

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @param string $procedure
     * @param string $action
     *
     * @throws BadRequestException
     */
    protected function validateStoredProcedureAccess(&$procedure, $action = null)
    {
        // check that the current user has privileges to access this function
        $resource = $procedure;
        if (!empty($procedure)) {
            $resource .= rtrim((false !== strpos($procedure, '(')) ? strstr($procedure, '(', true) : $procedure);
        }

        $this->checkPermission($action, $resource);
    }

    /**
     * Runs pre process tasks/scripts
     */
    protected function preProcess()
    {
        switch (count($this->resourceArray)) {
            case 0:
                parent::preProcess();
                break;
            case 1:
                // Try the generic table event
                /** @noinspection PhpUnusedLocalVariableInspection */
                $results = \Event::fire(
                    new ResourcePreProcess(
                        $this->getServiceName(), $this->getFullPathName('.') . '.{procedure_name}', $this->request,
                        $this->resourcePath
                    )
                );
                // Try the actual table name event
                /** @noinspection PhpUnusedLocalVariableInspection */
                $results = \Event::fire(
                    new ResourcePreProcess(
                        $this->getServiceName(), $this->getFullPathName('.') . '.' . $this->resourceArray[0],
                        $this->request,
                        $this->resourcePath
                    )
                );
                break;
            default:
                // Do nothing is all we got?
                break;
        }
    }

    /**
     * Runs post process tasks/scripts
     */
    protected function postProcess()
    {
        switch (count($this->resourceArray)) {
            case 0:
                parent::postProcess();
                break;
            case 1:
                $event = new ResourcePostProcess(
                    $this->getServiceName(), $this->getFullPathName('.') . '.' . $this->resourceArray[0],
                    $this->request,
                    $this->response,
                    $this->resourcePath
                );
                /** @noinspection PhpUnusedLocalVariableInspection */
                $results = \Event::fire($event);
                // copy the event response back to this response
                $this->response = $event->response;

                $event = new ResourcePostProcess(
                    $this->getServiceName(), $this->getFullPathName('.') . '.{procedure_name}', $this->request,
                    $this->response,
                    $this->resourcePath
                );
                /** @noinspection PhpUnusedLocalVariableInspection */
                $results = \Event::fire($event);

                $this->response = $event->response;
                break;
            default:
                // Do nothing is all we got?
                break;
        }
    }

    /**
     * @return array|bool
     * @throws BadRequestException
     */
    protected function handleGET()
    {
        if (empty($this->resource)) {
            return parent::handleGET();
        }

        $payload = $this->request->getPayloadData();
        if (false !== strpos($this->resource, '(')) {
            $inlineParams = strstr($this->resource, '(');
            $name = rtrim(strstr($this->resource, '(', true));
            $params = ArrayUtils::get($payload, 'params', trim($inlineParams, '()'));
        } else {
            $name = $this->resource;
            $params = ArrayUtils::get($payload, 'params', []);
        }

        $returns = ArrayUtils::get($payload, 'returns');
        $wrapper = ArrayUtils::get($payload, 'wrapper');
        $schema = ArrayUtils::get($payload, 'schema');

        return $this->callProcedure($name, $params, $returns, $schema, $wrapper);
    }

    /**
     * @return array|bool
     * @throws BadRequestException
     */
    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if (false !== strpos($this->resource, '(')) {
            $inlineParams = strstr($this->resource, '(');
            $name = rtrim(strstr($this->resource, '(', true));
            $params = ArrayUtils::get($payload, 'params', trim($inlineParams, '()'));
        } else {
            $name = $this->resource;
            $params = ArrayUtils::get($payload, 'params', []);
        }

        $returns = ArrayUtils::get($payload, 'returns');
        $wrapper = ArrayUtils::get($payload, 'wrapper');
        $schema = ArrayUtils::get($payload, 'schema');

        return $this->callProcedure($name, $params, $returns, $schema, $wrapper);
    }

    /**
     * {@inheritdoc}
     */
    public function getResourceName()
    {
        return static::RESOURCE_NAME;
    }

    /**
     * @param null $schema
     * @param bool $refresh
     *
     * @return array
     * @throws InternalServerErrorException
     * @throws RestException
     * @throws \Exception
     */
    public function listResources($schema = null, $refresh = false)
    {
        try {
            return $this->dbConn->getSchema()->getProcedureNames($schema, $refresh);
        } catch (RestException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to list database stored procedures for this service.\n{$ex->getMessage()}");
        }
    }

    public function getResources($only_handlers = false)
    {
        if ($only_handlers) {
            return [];
        }

        $refresh = $this->request->getParameterAsBool('refresh');
        $schema = $this->request->getParameter('schema', '');

        $result = $this->listResources($schema, $refresh);
        $resources = [];
        foreach ($result as $name) {
            $access = $this->getPermissions($name);
            if (!empty($access)) {
                $resources[] = ['name' => $name, 'access' => VerbsMask::maskToArray($access)];
            }
        }

        return $resources;
    }

    /**
     * @param string $name
     * @param array  $params
     * @param string $returns
     * @param array  $schema
     * @param string $wrapper
     *
     * @throws \Exception
     * @return array
     */
    public function callProcedure($name, $params = null, $returns = null, $schema = null, $wrapper = null)
    {
        if (empty($name)) {
            throw new BadRequestException('Stored procedure name can not be empty.');
        }

        if (false === $params = DbUtilities::validateAsArray($params, ',', true)) {
            $params = [];
        }

        foreach ($params as $key => $param) {
            // overcome shortcomings of passed in data
            if (is_array($param)) {
                if (null === $pName = ArrayUtils::get($param, 'name', null, false)) {
                    $params[$key]['name'] = "p$key";
                }
                if (null === $pType = ArrayUtils::get($param, 'param_type', null, false)) {
                    $params[$key]['param_type'] = 'IN';
                }
                if (null === $pValue = ArrayUtils::get($param, 'value', null)) {
                    // ensure some value is set as this will be referenced for return of INOUT and OUT params
                    $params[$key]['value'] = null;
                }
                if (false !== stripos(strval($pType), 'OUT')) {
                    if (null === $rType = ArrayUtils::get($param, 'type', null, false)) {
                        $rType = (isset($pValue)) ? gettype($pValue) : 'string';
                        $params[$key]['type'] = $rType;
                    }
                    if (null === $rLength = ArrayUtils::get($param, 'length', null, false)) {
                        $rLength = 256;
                        switch ($rType) {
                            case 'int':
                            case 'integer':
                                $rLength = 12;
                                break;
                        }
                        $params[$key]['length'] = $rLength;
                    }
                }
            } else {
                $params[$key] = ['name' => "p$key", 'param_type' => 'IN', 'value' => $param];
            }
        }

        try {
            $result = $this->dbConn->getSchema()->callProcedure($name, $params);

            if (!empty($returns) && (0 !== strcasecmp('TABLE', $returns))) {
                // result could be an array of array of one value - i.e. multi-dataset format with just a single value
                if (is_array($result)) {
                    $result = current($result);
                    if (is_array($result)) {
                        $result = current($result);
                    }
                }
                $result = DbUtilities::formatValue($result, $returns);
            }

            // convert result field values to types according to schema received
            if (is_array($schema) && is_array($result)) {
                foreach ($result as &$row) {
                    if (is_array($row)) {
                        if (isset($row[0])) {
                            //  Multi-row set, dig a little deeper
                            foreach ($row as &$sub) {
                                if (is_array($sub)) {
                                    foreach ($sub as $key => $value) {
                                        if (null !== $type = ArrayUtils::get($schema, $key, null, false)) {
                                            $sub[$key] = DbUtilities::formatValue($value, $type);
                                        }
                                    }
                                }
                            }
                        } else {
                            foreach ($row as $key => $value) {
                                if (null !== $type = ArrayUtils::get($schema, $key, null, false)) {
                                    $row[$key] = DbUtilities::formatValue($value, $type);
                                }
                            }
                        }
                    }
                }
            }

            // wrap the result set if desired
            if (!empty($wrapper)) {
                $result = [$wrapper => $result];
            }

            // add back output parameters to results
            foreach ($params as $key => $param) {
                if (false !== stripos(strval(ArrayUtils::get($param, 'param_type')), 'OUT')) {
                    $name = ArrayUtils::get($param, 'name', "p$key");
                    if (null !== $value = ArrayUtils::get($param, 'value', null)) {
                        $type = ArrayUtils::get($param, 'type');
                        $value = DbUtilities::formatValue($value, $type);
                    }
                    $result[$name] = $value;
                }
            }

            return $result;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to call database stored procedure.\n{$ex->getMessage()}");
        }
    }

    public static function getApiDocInfo(Service $service, array $resource = [])
    {
        $serviceName = strtolower($service->name);
        $capitalized = Inflector::camelize($service->name);
        $class = trim(strrchr(static::class, '\\'), '\\');
        $resourceName = strtolower(ArrayUtils::get($resource, 'name', $class));
        $path = '/' . $serviceName . '/' . $resourceName;
        $eventPath = $serviceName . '.' . $resourceName;
        $base = parent::getApiDocInfo($service, $resource);

        $apis = [
            $path . '/{procedure_name}' => [
                'get'  => [
                    'tags'        => [$serviceName],
                    'summary'     => 'call'.$capitalized.'StoredProcedure() - Call a stored procedure.',
                    'operationId' => 'call'.$capitalized.'StoredProcedure',
                    'description' =>
                        'Call a stored procedure with no parameters. ' .
                        'Set an optional wrapper for the returned data set. ',
                    'event_name'  => [
                        $eventPath . '.{procedure_name}.call',
                        $eventPath . '.procedure_called',
                    ],
                    'parameters'  => [
                        [
                            'name'        => 'procedure_name',
                            'description' => 'Name of the stored procedure to call.',
                            'type'        => 'string',
                            'in'          => 'path',
                            'required'    => true,
                        ],
                        [
                            'name'        => 'wrapper',
                            'description' => 'Add this wrapper around the expected data set before returning.',
                            'type'        => 'string',
                            'in'          => 'query',
                            'required'    => false,
                        ],
                        [
                            'name'        => 'returns',
                            'description' => 'If returning a single value, use this to set the type of that value.',
                            'type'        => 'string',
                            'in'          => 'query',
                            'required'    => false,
                        ],
                    ],
                    'responses'   => [
                        '200'     => [
                            'description' => 'Success',
                            'schema'      => ['$ref' => '#/definitions/StoredProcedureResponse']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                ],
                'post' => [
                    'tags'        => [$serviceName],
                    'summary'     => 'call'.$capitalized.'StoredProcedureWithParams() - Call a stored procedure.',
                    'operationId' => 'call'.$capitalized.'StoredProcedureWithParams',
                    'description' =>
                        'Call a stored procedure with parameters. ' .
                        'Set an optional wrapper and schema for the returned data set. ',
                    'event_name'  => [
                        $eventPath . '.{procedure_name}.call',
                        $eventPath . '.procedure_called',
                    ],
                    'parameters'  => [
                        [
                            'name'        => 'procedure_name',
                            'description' => 'Name of the stored procedure to call.',
                            'type'        => 'string',
                            'in'          => 'path',
                            'required'    => true,
                        ],
                        [
                            'name'        => 'body',
                            'description' => 'Data containing in and out parameters to pass to procedure.',
                            'schema'      => ['$ref' => '#/definitions/StoredProcedureRequest'],
                            'in'          => 'body',
                            'required'    => true,
                        ],
                        [
                            'name'        => 'wrapper',
                            'description' => 'Add this wrapper around the expected data set before returning.',
                            'type'        => 'string',
                            'in'          => 'query',
                            'required'    => false,
                        ],
                        [
                            'name'        => 'returns',
                            'description' => 'If returning a single value, use this to set the type of that value.',
                            'type'        => 'string',
                            'in'          => 'query',
                            'required'    => false,
                        ],
                    ],
                    'responses'   => [
                        '200'     => [
                            'description' => 'Success',
                            'schema'      => ['$ref' => '#/definitions/StoredProcedureResponse']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                ],
            ],
        ];

        $models = [
            'StoredProcedureResponse'     => [
                'type'       => 'object',
                'properties' => [
                    '_wrapper_if_supplied_' => [
                        'type'        => 'Array',
                        'description' => 'Array of returned data.',
                        'items'       => [
                            'type' => 'string'
                        ],
                    ],
                    '_out_param_name_'      => [
                        'type'        => 'string',
                        'description' => 'Name and value of any given output parameter.',
                    ],
                ],
            ],
            'StoredProcedureRequest'      => [
                'type'       => 'object',
                'properties' => [
                    'params'  => [
                        'type'        => 'array',
                        'description' => 'Optional array of input and output parameters.',
                        'items'       => [
                            '$ref' => '#/definitions/StoredProcedureParam',
                        ],
                    ],
                    'schema'  => [
                        'type'        => 'StoredProcedureResultSchema',
                        'description' => 'Optional name to type pairs to be applied to returned data.',
                    ],
                    'wrapper' => [
                        'type'        => 'string',
                        'description' => 'Add this wrapper around the expected data set before returning, same as URL parameter.',
                    ],
                    'returns' => [
                        'type'        => 'string',
                        'description' => 'If returning a single value, use this to set the type of that value, same as URL parameter.',
                    ],
                ],
            ],
            'StoredProcedureParam'        => [
                'type'       => 'object',
                'properties' => [
                    'name'       => [
                        'type'        => 'string',
                        'description' =>
                            'Name of the parameter, required for OUT and INOUT types, ' .
                            'must be the same as the stored procedure\'s parameter name.',
                    ],
                    'param_type' => [
                        'type'        => 'string',
                        'description' => 'Parameter type of IN, OUT, or INOUT, defaults to IN.',
                    ],
                    'value'      => [
                        'type'        => 'string',
                        'description' => 'Value of the parameter, used for the IN and INOUT types, defaults to NULL.',
                    ],
                    'type'       => [
                        'type'        => 'string',
                        'description' =>
                            'For INOUT and OUT parameters, the requested type for the returned value, ' .
                            'i.e. integer, boolean, string, etc. Defaults to value type for INOUT and string for OUT.',
                    ],
                    'length'     => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' =>
                            'For INOUT and OUT parameters, the requested length for the returned value. ' .
                            'May be required by some database drivers.',
                    ],
                ],
            ],
            'StoredProcedureResultSchema' => [
                'type'       => 'object',
                'properties' => [
                    '_field_name_' => [
                        'type'        => 'string',
                        'description' =>
                            'The name of the returned element where the value is set to the requested type ' .
                            'for the returned value, i.e. integer, boolean, string, etc.',
                    ],
                ],
            ],
        ];

        $base['paths'] = array_merge($base['paths'], $apis);
        $base['definitions'] = array_merge($base['definitions'], $models);

        return $base;
    }
}