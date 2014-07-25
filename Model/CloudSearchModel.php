<?php
App::uses('Model', 'Model');

class CloudSearchModel extends Model {
  public $useDbConfig = 'cloud_search';

  public function exists($id = null) {
    return true;
  }

  public function paginateCount($conditions, $recursive, $extra) {
    $count = 0;
    if (!empty($this->getDataSource()->searchResult)) {
      $count = $this->getDataSource()->searchResult->getPath('hits/found');
    }
    return $count;
  }

  public function getFacets() {
    $facets = [];
    if (!empty($this->getDataSource()->searchResult)) {
      $facets = $this->getDataSource()->searchResult->getPath('facets');
    }
    return $facets;
  }
}
