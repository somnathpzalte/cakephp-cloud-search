<?php
App::uses('Model', 'Model');

class CloudSearchModel extends Model {
  public $useDbConfig = 'cloud_search';

  public function exists($id = null) {
    return true;
  }
}
