<?php

App::uses('DataSource', 'Model/Datasource');

use Aws\CloudSearch\CloudSearchClient;
use Aws\CloudSearchDomain\CloudSearchDomainClient;

class CloudSearchSource extends DataSource {

  public $_client = null;
  public $_docClient = null;
  public $_searchClient = null;
  public $searchResult = null;

  public function __construct($config = array()) {
    parent::__construct($config);
  }


  public function read(Model $Model, $queryData = array(), $recursive = null) {
    $return = [];

    $this->_useTable($Model);
    if (!$this->_isReadySearchClient($Model->useTable)) {
      return false;
    }

    if (empty($query = Hash::get($queryData, 'conditions.query'))) {
      return false;
    }

    if (!empty($queryData['fields'])) {
      $queryData['conditions']['return'] = implode(',', $queryData['fields']);
    }

    if (!empty($queryData['limit'])) {
      $queryData['conditions']['size'] = intval($queryData['limit']);
    }

    if (!empty($queryData['offset'])) {
      $queryData['conditions']['start'] = intval($queryData['offset']);
    }

    if (!empty($queryData['facet']) && is_array($queryData['facet'])) {
      $queryData['conditions']['facet'] = json_encode($queryData['facet']);
    }

    if ($Model->findQueryType == 'count') {
      unset($queryData['fields']['count']);
    }

    $this->searchResult = $this->_searchClient->search($queryData['conditions']);

    $found = intval($this->searchResult->getPath('hits/found'));
    if ($Model->findQueryType == 'count') {
      return [[['count' => $found]]];
    }

    if ($found) {
      foreach ($this->searchResult->getPath('hits/hit') as $hit) {
        $record = [];
        $record[$Model->primaryKey] = $hit['id'];
        if (!empty($hit['fields'])) {
          foreach($hit['fields'] as $key => $value) {
            $record[$key] = count($value) == 1 ? $value[0] : $value;
          }
        }
        $return[][$Model->alias] = $record;
      }
    }

    return $return;
  }

  public function create(Model $Model, $fields = null, $values = null) {
    $this->_useTable($Model);
    if (!$this->_isReadyDocClient($Model->useTable)) {
      return false;
    }

    if ($Model->primaryKey !== 'id') {
      $id = $values[$Model->primaryKey];
      unset($fields[$Model->primaryKey], $values[$Model->primaryKey]);
      $fields[] = 'id';
      $values[] = $id;
    }

    $fields[] = 'type';
    $values[] = 'add';

    // strip ascii code
    array_walk_recursive($values, function(&$v) { $v = preg_replace('/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', '', $v); }, $values);

    $documents = [];
    $documents[] = array_combine($fields, $values);
    try {
      $response = $this->_docClient->uploadDocuments(['documents' => json_encode($documents), 'contentType' => 'application/json']);
      if (!empty($response) || $response['status'] === 'success') {
        return true;
      }
    } catch(Exception $e) {
      throw new CakeException($e->getMessage());
    }
    return false;
  }

  public function update(Model $Model, $fields = null, $values = null, $conditions = null) {
    return $this->create($Model, $fields, $values);
  }

  public function delete(Model $Model, $conditions = null) {

    $this->_useTable($Model);
    if (!$this->_isReadyDocClient($Model->useTable)) {
      return false;
    }

    $documents = [];

    if (!empty($id = $conditions["{$Model->alias}.id"])) {
      if (is_scalar($id)) {
        $documents[] = [
          'type' => 'delete',
          'id' => $id,
        ];
      } elseif (is_array($id)) {
        foreach ($id as $v) {
          $documents[] = [
            'type' => 'delete',
            'id' => $v,
          ];
        }
      }
    }

    if (empty($documents)) {
      return false;
    }

    try {
      $response = $this->_docClient->uploadDocuments(['documents' => json_encode($documents), 'contentType' => 'application/json']);
      if (!empty($response) || $response['status'] === 'success') {
        return true;
      }
    } catch(Exception $e) {
      throw new CakeException($e->getMessage());
    }
    return false;
  }

  public function query($method, $params, $model) {
    // return call_user_func_array(array($this->_connection, $method), $params);
  }

  public function describe($model) {
    return [
      $model->primaryKey => [
        'type' => 'string',
        'null' => false,
        'length' => 255,
        'key' => 'primary'
      ],
      'fields' => [
        'type' => 'array',
        'null' => true,
        'default' => [],
      ]
    ];
  }

  public function calculate(Model $Model, $func, $params = []) {
    return ['count' => true];
  }

  public function close() {
    unset($this->_client, $this->_docClient, $this->_searchClient);
    $this->_client = null;
    $this->_docClient = null;
    $this->_searchClient = null;
    return true;
  }

  public function listSources($data = null) {
    if (!empty($this->config['domain'])) {
      // Set useTable later
      return null;
    } elseif ($client = $this->_factoryClient()) {
      return array_keys($client->listDomainNames()['DomainNames']);
    }
    return null;
  }

  public function getSchemaName() {
    return $this->config['region'];
  }

  protected function _isReadySearchClient($domain) {
    if ($this->_searchClient === false) {
      return false;
    }
    return $this->_factoryEndpointClient('search', $domain);
  }

  protected function _isReadyDocClient($domain) {
    if ($this->_docClient === false) {
      return false;
    }
    return $this->_factoryEndpointClient('doc', $domain);
  }

  protected function _factoryEndpointClient($type, $domain) {
    $var = sprintf('_%sClient', $type);
    $this->{$var} = false;

    $key = sprintf('%s_%s_%s_endpoint',
      ConnectionManager::getSourceName($this),
      $domain,
      $type
    );
    $key = preg_replace('/[^A-Za-z0-9_\-.+]/', '_', $key);
    $endpoint = Cache::read($key, '_cake_model_');

    if (empty($endpoint) && $client = $this->_factoryClient()) {
      $result = $client->describeDomains(['DomainNames' => [$domain]]);
      $service = Inflector::camelize($type) . 'Service';
      $endpoint = $result['DomainStatusList'][0][$service]['Endpoint'];
      Cache::write($key, $endpoint, '_cake_model_');
    }

    if (!empty($endpoint)) {
      $this->{$var} = CloudSearchDomainClient::factory([
        'base_url' => $endpoint,
      ]);
    }

    return $this->{$var};
  }


  protected function _factoryClient() {
    if ($this->_client !== null) {
      return $this->_client;
    }

    $client = false;

    try {
      $client = CloudSearchClient::factory([
        'key' => $this->config['key'],
        'secret' => $this->config['secret'],
        'region' => $this->config['region'],
      ]);
    } catch(Exception $e) {
      throw new MissingConnectionException([
        'class' => get_class($this),
        'message' => $e->getMessage(),
      ]);
    }

    $this->_client = $client;
    return $client;
  }

  protected function _useTable($Model) {
    if (!empty($this->config['domain'])) {
      $Model->setSource($this->config['domain']);
    }
  }
}
