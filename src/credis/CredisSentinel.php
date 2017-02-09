<?php

namespace queues\credis;

/**
 * CredisSentinel
 *
 * Implements the Sentinel API as mentioned on http://redis.io/topics/sentinel.
 * Sentinel is aware of master and slave nodes in a cluster and returns instances of CredisClient accordingly.
 *
 * The complexity of read/write splitting can also be abstract by calling the createCluster() method which returns a
 * CredisCluster object that contains both the master server and a random slave. CredisCluster takes care of the
 * read/write splitting
 *
 * @author Thijs Feryn <thijs@feryn.eu>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package CredisSentinel
 */
class CredisSentinel
{
    /**
     * Contains a client that connects to a Sentinel node.
     * Sentinel uses the same protocol as Redis which makes using CredisClient convenient.
     * @var CredisClient
     */
    protected $_client;
    /**
     * Contains an active instance of CredisCluster per master pool
     * @var Array
     */
    protected $_cluster = array();
    /**
     * Contains an active instance of CredisClient representing a master
     * @var Array
     */
    protected $_master = array();
    /**
     * Contains an array CredisClient objects representing all slaves per master pool
     * @var Array
     */
    protected $_slaves = array();
    /**
     * Use the phpredis extension or the standalone implementation
     * @var bool
     */
    protected $_standAlone = false;
    /**
     * Store the AUTH password used by CredisClient instances
     * @var string
     */
    protected $_authPassword = '';

    /**
     * Connect with a Sentinel node. Sentinel will do the master and slave discovery
     *
     * @param CredisClient $client
     * @param string $password
     * @throws CredisException
     */
    public function __construct(CredisClient $client, $password = NULL)
    {
        if(!$client instanceof CredisClient){
            throw new CredisException('Sentinel client should be an instance of CredisClient');
        }
        $client->forceStandalone();
        $this->_client = $client;
        $this->_authPassword = $password;
    }
    /**
     * @return CredisSentinel
     */
    public function forceStandalone()
    {
        $this->_standAlone = true;
        return $this;
    }

    /**
     * Discover the master node automatically and return an instance of CredisClient that connects to the master
     *
     * @param string $name
     * @return CredisClient
     * @throws CredisException
     */
    public function createMasterClient($name)
    {
        $master = $this->getMasterAddressByName($name);
        if(!isset($master[0]) || !isset($master[1])){
            throw new CredisException('Master not found');
        }
        return new CredisClient($master[0],$master[1],null,'',0,$this->_authPassword);
    }

    /**
     * If a CredisClient object exists for a master, return it. Otherwise create one and return it
     * @param string $name
     * @return CredisClient
     */
    public function getMasterClient($name)
    {
        if(!isset($this->_master[$name])){
            $this->_master[$name] = $this->createMasterClient($name);
        }
        return $this->_master[$name];
    }

    /**
     * Discover the slave nodes automatically and return an array of CredisClient objects
     *
     * @param string $name
     * @return CredisClient[]
     * @throws CredisException
     */
    public function createSlaveClients($name)
    {
        $slaves = $this->slaves($name);
        $workingSlaves = array();
        foreach($slaves as $slave) {
            if(!isset($slave[9])){
                throw new CredisException('Can\' retrieve slave status');
            }
            if(!strstr($slave[9],'s_down') && !strstr($slave[9],'disconnected')) {
                $workingSlaves[] = new CredisClient($slave[3],$slave[5],null,'',0,$this->_authPassword);
            }
        }
        return $workingSlaves;
    }

    /**
     * If an array of CredisClient objects exist for a set of slaves, return them. Otherwise create and return them
     * @param string $name
     * @return CredisClient[]
     */
    public function getSlaveClients($name)
    {
        if(!isset($this->_slaves[$name])){
            $this->_slaves[$name] = $this->createSlaveClients($name);
        }
        return $this->_slaves[$name];
    }

    /**
     * Returns a Redis cluster object containing a random slave and the master
     * When $selectRandomSlave is true, only one random slave is passed.
     * When $selectRandomSlave is false, all clients are passed and hashing is applied in CredisCluster
     * When $writeOnly is false, the master server will also be used for read commands.
     *
     * @param string $name
     * @param int $db
     * @param int $replicas
     * @param bool $selectRandomSlave
     * @param bool $writeOnly
     * @return CredisCluster
     * @throws CredisException
     */
    public function createCluster($name, $db=0, $replicas=128, $selectRandomSlave=true, $writeOnly=false)
    {
        $clients = array();
        $workingClients = array();
        $master = $this->master($name);
        if(strstr($master[9],'s_down') || strstr($master[9],'disconnected')) {
            throw new CredisException('The master is down');
        }
        $slaves = $this->slaves($name);
        foreach($slaves as $slave){
            if(!strstr($slave[9],'s_down') && !strstr($slave[9],'disconnected')) {
                $workingClients[] =  array('host'=>$slave[3],'port'=>$slave[5],'master'=>false,'db'=>$db,'password'=>$this->_authPassword);
            }
        }
        if(count($workingClients)>0){
            if($selectRandomSlave){
                if(!$writeOnly){
                    $workingClients[] = array('host'=>$master[3],'port'=>$master[5],'master'=>false,'db'=>$db,'password'=>$this->_authPassword);
                }
                $clients[] = $workingClients[rand(0,count($workingClients)-1)];
            } else {
                $clients = $workingClients;
            }
        }
        $clients[] = array('host'=>$master[3],'port'=>$master[5], 'db'=>$db ,'master'=>true,'write_only'=>$writeOnly,'password'=>$this->_authPassword);
        return new CredisCluster($clients,$replicas,$this->_standAlone);
    }

    /**
     * If a CredisCluster object exists, return it. Otherwise create one and return it.
     * @param string $name
     * @param int $db
     * @param int $replicas
     * @param bool $selectRandomSlave
     * @param bool $writeOnly
     * @return CredisCluster
     */
    public function getCluster($name, $db=0, $replicas=128, $selectRandomSlave=true, $writeOnly=false)
    {
        if(!isset($this->_cluster[$name])){
            $this->_cluster[$name] = $this->createCluster($name, $db, $replicas, $selectRandomSlave, $writeOnly);
        }
        return $this->_cluster[$name];
    }

    /**
     * Catch-all method
     * @param string $name
     * @param array $args
     * @return mixed
     */
    public function __call($name, $args)
    {
        array_unshift($args,$name);
        return call_user_func(array($this->_client,'sentinel'),$args);
    }

    /**
     * Return information about all registered master servers
     * @return mixed
     */
    public function masters()
    {
        return $this->_client->sentinel('masters');
    }

    /**
     * Return all information for slaves that are associated with a single master
     * @param string $name
     * @return mixed
     */
    public function slaves($name)
    {
        return $this->_client->sentinel('slaves',$name);
    }

    /**
     * Get the information for a specific master
     * @param string $name
     * @return mixed
     */
    public function master($name)
    {
        return $this->_client->sentinel('master',$name);
    }

    /**
     * Get the hostname and port for a specific master
     * @param string $name
     * @return mixed
     */
    public function getMasterAddressByName($name)
    {
        return $this->_client->sentinel('get-master-addr-by-name',$name);
    }

    /**
     * Check if the Sentinel is still responding
     * @param string $name
     * @return mixed
     */
    public function ping()
    {
        return $this->_client->ping();
    }

    /**
     * Perform an auto-failover which will re-elect another master and make the current master a slave
     * @param string $name
     * @return mixed
     */
    public function failover($name)
    {
        return $this->_client->sentinel('failover',$name);
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->_client->getHost();
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return $this->_client->getPort();
    }
}
