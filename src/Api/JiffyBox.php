<?php

namespace Api;

/**
 * JiffyBox client.
 */
class JiffyBox
{
    /**@+
     * @var integer
     */
    const JB_READY = 'READY';
    const JB_FROZEN = 'FROZEN';
    const JB_FREEZE = 'FREEZE';
    const JB_START = 'START';
    const JB_SHUTDOWN = 'SHUTDOWN';
    const JB_PULLPLUG = 'PULLPLUG';
    const JB_THAW = 'THAW';
    #@-

    /**@+
     * internal
     * @var string
     */
    private $apiUrl;
    private $lastError;
    private $lastMessages;
    #@-

    /**
     *
     * @var integer
     */
    protected $id;

    /**@+
     *
     * @var string
     */
    protected $apiPath = 'https://api.jiffybox.de/';
    protected $apiVersion = 'v1.0';
    #@-

    /**
     *
     * @param string  $token
     * @param integer $id
     */
    public function __construct($token)
    {
        $this->apiUrl = $this->apiPath . $token . '/' . $this->apiVersion . '/';
    }

    /**
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param integer $id
     */
    public function setId($id)
    {
        $this->id = (int) $id;
    }

    /**
     *
     * @param integer $id
     * @param string  $method
     * @param array   $data
     * @param string  $command
     */
    private function requestCurl($id, $method = 'GET', array $data = null, $command = 'jiffyBoxes')
    {
        $this->lastCurlError = null;
        $this->lastMessages = null;

        $url = $this->apiUrl . $command . ($id === null ? '' : '/' . $id);
        $ch = curl_init($url);

        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        );

        switch (strtoupper($method)) {
            case 'POST':
                $data = http_build_query($data);
                $options[CURLOPT_POST] = 1;
                $options[CURLOPT_POSTFIELDS] = $data;
                break;

            case 'GET':
                $options[CURLOPT_HTTPGET] = 1;
                break;

            case 'PUT':
                $data = http_build_query($data);
                $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $options[CURLOPT_HTTPHEADER] = array('Content-Length: ' . strlen($data));
                $options[CURLOPT_POSTFIELDS] = $data;
                break;

            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        if (!$response) {
            $this->lastCurlError = curl_error($ch);
            return false;
        }

        curl_close($ch);

        $data = json_decode($response);
        if (!$data) {
            throw new Exception('Invalid response');
        }
        // TODO properly handle messages (allow listeners)
        $this->lastMessages = $data->messages;

        return $data->result;
    }

    /**
     * @return string
     */
    public function getCurlError()
    {
        return $this->lastCurlError;
    }

    /**
     * @return array
     */
    public function getLastMessages()
    {
        return $this->lastMessages;
    }

    /**
     *
     * @return mixed
     */
    public function getBackups()
    {
        $json = $this->requestCurl($this->id, 'GET', null, 'backups');
        return json_decode($json);
    }

    /**
     *
     * @param  string  $name
     * @param  integer $planId
     * @param  integer $backupId
     * @param  string  $distribution
     * @param  string  $password
     * @param  boolean $useSshKey
     * @param  string  $metadata
     * @return mixed
     */
    public function create(
        $name,
        $planId,
        $backupId = null,
        $distribution = null,
        $password = null,
        $useSshKey = null,
        $metadata = null
    ) {
        $data = array (
            'name' => $name,
            'planid' => $planId,
        );
        if (isset($backupId)) {
            $data['backupid'] = $backupId;
        }
        if (isset($distribution)) {
            $data['distribution'] = $distribution;
        }
        if (isset($password)) {
            $data['password'] = $password;
        }
        if (isset($useSshKey)) {
            $data['use_sshkey'] = $useSshKey;
        }
        if (isset($useSshKey)) {
            $data['metadata'] = $metadata;
        }

        return $this->requestCurl(null, 'POST', $data);
    }

    /**
     *
     * @param  string  $name
     * @param  integer $planId
     * @return mixed
     */
    public function createClone($name, $planId)
    {
        $data = array (
            'name' => $name,
            'planid' => $planId
        );
        return $this->requestCurl($this->id, 'POST', $data);
    }

    public function delete()
    {
        return $this->requestCurl($this->id, 'DELETE');
    }

    /**
     *  @return boolean
     */
    public function freeze()
    {
        return $this->setStatus(self::JB_FREEZE);
    }

    /**
     * @return array
     */
    public function get()
    {
        return $this->requestCurl($this->id, 'GET');
    }

    /**
     *  @return boolean
     */
    public function start()
    {
        return $this->setStatus(self::JB_START);
    }

    /**
     *
     * @return mixed
     */
    public function stop()
    {
        $this->setStatus(self::JB_SHUTDOWN);
    }

    /**
     *
     * @return mixed
     */
    public function pullPlug()
    {
        $this->setStatus(self::JB_PULLPLUG);
    }

    /**
     *
     * @param  integer $planId
     * @return mixed
     */
    public function thaw($planId)
    {
        if ($this->getStatus() == self::JB_FROZEN) {
            $data = array(
                'status' => self::JB_THAW,
                'planid' => (int) $planId
            );
            return $this->requestCurl($this->id, 'PUT', $data);
        }
    }

    /**
     *  @return boolean
     */
    public function getStatus()
    {
        $json = $this->requestCurl($this->id, 'GET');
        return $json->status;
    }

    /**
     * Set status for READY boxes.
     * Can not thaw FROZEN box.
     *
     * @param string $state
     * @return boolean
     */
    public function setStatus($status)
    {
        if ($this->getStatus() == self::JB_READY) {
            $data = array('status' => (string) $status);
            return $this->requestCurl($this->id, 'PUT', $data);
        }
    }

    /**
     *  @return array
     */
    public function getDistributions()
    {
        return $this->requestCurl(null, 'GET', null, 'distributions');
    }

    /**
     *  @return array
     */
    public function getDoc($command = null)
    {
        $command = $command === null ? '' : '/' . $command;
        return $this->requestCurl(null, 'GET', null, 'doc' . $command);
    }

    /**
     *  @return array
     */
    public function getIps()
    {
        return $this->requestCurl(null, 'GET', null, 'ips');
    }

    /**
     *
     * @return array
     */
    public function getJiffyBoxes()
    {
        return $this->requestCurl(null, 'GET');
    }

    /**
     *  @return array
     */
    public function getPlans()
    {
        return $this->requestCurl(null, 'GET', null, 'plans');
    }
}
