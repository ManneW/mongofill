<?php

use Mongofill\Protocol;

class MongoCollection
{
    /**
     * @var string
     */
    private $fqn;

    /**
     * @var string
     */
    private $name;

    /**
     * @var MongoDB
     */
    private $db;

    /**
     * @var MongoClient
     */
    private $client;

    /**
     * @var Protocol
     */
    private $protocol;

    /**
     * @param MongoDB $db
     * @param string $name
     */
    function __construct(MongoDB $db, $name)
    {
        $this->db       = $db;
        $this->name     = $name;
        $this->fqn      = $db->_getFullCollectionName($name);
        $this->client   = $db->_getClient();
        $this->protocol = $this->client->_getProtocol();
    }

    public function count(array $query = [], $limit = 0, $skip = 0)
    {
        $result = $this->db->command([
            'count' => $this->name,
            'query' => $query, 
            'limit' => $limit,
            'skip' => $skip
        ]);

        if(isset($result['ok'])){
            return (int) $result['n'];
        }

        return false;
    }

    /**
     * @param array $query
     * @param array $fields
     * @return MongoCursor
     */
    public function find(array $query = [], array $fields = [])
    {
        return new MongoCursor($this->client, $this->fqn, $query, $fields);
    }

    /**
     * @param array $query
     * @param array $fields
     * @return array or null
     */
    public function findOne(array $query = [], array $fields = [])
    {
        $cur = $this->find($query, $fields)->limit(1);
        return ($cur->valid()) ? $cur->current() : null;
    }


    /**
     * Drop the current collection
     * @returns array
     */

    public function drop()
    {
        $this->db->command(['drop' => $this->name]);
    }

    /**
     * Return the collection name - NOT the fqn
     * @return string
     */

    public function getName()
    {
        return $this->name;
    }

    /**
     * Insert a document
     * @param array $a
     * @param array $options
     * @returns bool|array
     */
    public function insert(array &$document, array $options = [])
    {
        $documents = [&$document];
        $this->batchInsert($documents, $options);

        // Fake response for async insert -
        // TODO: detect "w" option and return status array
        return true;
    }

    /**
     * Insert a set of documents
     * @param array $a
     * @param array $options
     * @returns bool|array
     */
    public function batchInsert(array &$documents, array $options = [])
    {
        $this->createMongoIdsIfMissing($documents);
        $this->protocol->opInsert($this->fqn, $documents, false);

        // Fake response for async insert -
        // TODO: detect "w" option and return status array
        return true;
    }

    private function createMongoIdsIfMissing(array &$documents)
    {
        $count = count($documents);
        $keys = array_keys($documents);
        for ($i=0; $i < $count; $i++) { 
            if (!isset($documents[$keys[$i]]['_id'])) {
                $documents[$keys[$i]]['_id'] = new MongoId();
            }
        }
    }
    
    /**
     * @param       array $criteria Query specifing objects to be updated
     * @param       array $new_object document to update
     * @param       array $options
     *
     * @return bool
     */
    public function update(array $criteria, array $newObject, array $options = [])
    {
         $this->protocol->opUpdate($this->fqn, $criteria, $newObject, $options);
    }

    public function save($document, array $options = [])
    {
        if(!$document){
            return false;
        }

        if(!isset($document['_id'])){
            $this->update(['_id' => $document['_id']], $document, $options);
        } else {
            return $this->insert($document, $options);
        }

        //TODO: Handle timeout
        return true;
    }

    public function remove(array $criteria = [], array $options = [])
    {
        $this->protocol->opDelete($this->fqn, $criteria, $options);
    }

    /**
     * @param boolean $scanData Enable scan of base class
     * @param boolean $full
     */
    public function validate($full = false, $scanData = false)
    {
        $result =  $this->db->command([
            'validate' => $this->name, 
            'full' => $full, 
            'scandata' => $scanData
        ]);
        
        if(!empty($result)){
            return $result;
        }

        return false;
    }

    protected static function toIndexString($keys)
    {
        if (is_string($keys)) {
            return self::toIndexStringFromString($keys);
        } else if (is_object($keys)) {
            $keys = get_object_vars($keys);
        }

        if (is_array($keys)) {
            return self::toIndexStringFromArray($keys);
        }
            
        trigger_error('MongoCollection::toIndexString(): The key needs to be either a string or an array', E_USER_WARNING);
        
        return null;
    }

    private static function toIndexStringFromString($keys)
    {
        return str_replace('.', '_', $keys . '_1');
    }

    private static function toIndexStringFromArray(array $keys)
    {
        $prefValue = null;
        if (isset($keys['weights'])) {
            $keys = $keys['weights'];
            $prefValue = 'text';
        }

        $keys = (array) $keys;
        foreach ($keys as $key => $value) {
            if ($prefValue) {
                $value = $prefValue;
            }

            $keys[$key] = str_replace('.', '_', $key . '_' . $value);
        }

        return implode('_', $keys);  
    }

    public function deleteIndex($keys)
    {
        $cmd = [
            'deleteIndexes' => $this->name, 
            'index' => self::toIndexString($keys)
        ];

        return $this->db->command($cmd);  
    }

    public function deleteIndexes()
    {
        return (bool) $this->db->getIndexesCollection()->drop();
    }

    public function ensureIndex($keys, array $options = [])
    {
        if (!is_array($keys)) {
            $keys = [$keys => 1];
        }

        $index = [
            'ns' => $this->fqn,
            'name' => self::toIndexString($keys, $options),
            'key' => $this->fixNumberLongIndexes($keys)
        ];

        $insertOptions = [];
        if (isset($options['safe'])) {
            $insertOptions['safe'] = $options['safe'];
        }

        if (isset($options['w'])) {
            $insertOptions['w'] = $options['w'];
        }

        if (isset($options['fsync'])) {
            $insertOptions['fsync'] = $options['fsync'];
        }

        if (isset($options['timeout'])) {
            $insertOptions['timeout'] = $options['timeout'];
        }

        $index = array_merge($index, $options);

        return (bool) $this->db->getIndexesCollection()->insert(
            $index, 
            $insertOptions
        );        
    }

    private function fixNumberLongIndexes(array $keys)
    {
        $fixedKeys = [];
        foreach ($keys as $key => $value) {
            $fixedKeys[$key] = (float) $value;
        }

        return $fixedKeys;
    }

    public function getIndexInfo()
    {
        $indexes = $this->db->getIndexesCollection()->find([
            'ns' => $this->fqn
        ]);

        return iterator_to_array($indexes);
    }

    /**
     * __toString return full name of collections.
     * @return string
     */
    public function __toString()
    {
        return $this->fqn;
    }
}
