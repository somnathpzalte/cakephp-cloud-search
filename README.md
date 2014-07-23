cakephp-cloud-search
====================

Amazon CloudSearch DataSource Plugin for CakePHP


## Requirements
- PHP5
- CakePHP2
- [aws-sdk-php](https://github.com/aws/aws-sdk-php)


## Installation

```sh
cd app/Plugin
git clone git@github.com:nanapi/cakephp-cloud-search.git CloudSearch
```

app/Config/bootstrap.php
```
CakePlugin::load('CloudSearch');
```

app/Config/database.php
```php
<?php

class DATABASE_CONFIG {

  public $cloud_search = [
    'datasource' => 'CloudSearch.CloudSearchSource',
    'key'        => 'AWS_ACCESS_KEY_HERE',
    'secret'     => 'AWS_SECRET_HERE',
    'region'     => 'REGION',
  ];

```


## How to use it

your model
```php
<?php

App::uses('CloudSearchModel', 'CloudSearch.Model');
class MyModel extends CloudSearchModel {
  public $useTable  = 'DOMAIN';
}

```

your controller
```php
<?php
App::uses('AppController', 'Controller');
class MyController extends AppController {
  public $uses = [
    'MyModel';
  ];

  public function index() {
    // save
    $save_data = $this->MyModel->create();
    $save_data = [
      'id' => 'unique_id',
      'fields' => [
        'index_name1' => 'value1'
        'index_name2' => ['value2', 'value3']
      ]
    ];
    $this->MyModel->save($save_data);

    // find
    $result = $this->MyModel>find('all', [
      'conditions' => [
        'query' => 'value1|value2'
        'return' => '_all_fields,_score',
      ],
    ]);


    // delete
    $id = 'unique_id';
    $this->MyModel->delete($id);

    $this->MyModel->deleteAll([
      'MyModel.id' => ['unique_id_1', 'unique_id_2', 'unique_id_3'],
    ]);
  }


```

## ToDo
- query method (findBy)
