<?php

namespace Lineten\CurlClient;

/**
 * Class Multi
 */
class Multi
{
    /** @var resource */
    private $mh;

    /** @var int */
    public $loopWaitTime = 100;

    /** @var int */
    public $loopTimeout = 1000000;

    /** @var array $handles */
    public $handles = [];

    /**
     * CurlMulti constructor.
     * @param array $options
     */
    public function __construct($options = [])
    {
        $this->mh = curl_multi_init();

        foreach ($options as $k => $v) {
            curl_multi_setopt($this->mh, $k, $v);
        }
    }

    public function __destruct()
    {
        curl_multi_close($this->mh);
        $this->mh = null;
    }

    /**
     * Fetch information from curl
     * @param resource $mh curl multi resource
     * @param int $stillRunning number of running curl handles
     * @param int $timeout in microseconds
     * @return int
     */
    private function exec($mh, &$stillRunning, $timeout = 1000000)
    {
        // In theory curl_multi_exec should never return CURLM_CALL_MULTI_PERFORM (-1) because it has been deprecated
        // In practice it sometimes does
        // So imagine that this just runs curl_multi_exec once and returns a value
        // but most of the time it's CURLM_OK (0)
        do {
            $state = curl_multi_exec($mh, $stillRunning);

            // curl_multi_select($mh, $timeout) simply blocks for $timeout seconds
            // while curl_multi_exec() returns CURLM_CALL_MULTI_PERFORM (-1)
            // We add it to prevent CPU 100% usage in case this thing misbehaves and actually does return -1
        } while ($stillRunning > 0 && $state === -1 && curl_multi_select($mh, $timeout / 1000000));
        return $state;
    }

    /**
     * Wait until there is some new activity
     * This function exists to prevent the loop from looping at ligh speed and reaching 100% CPU
     * @param resource $mh curl multi resource
     * @param int $wait minimum time to wait between each loop in microseconds
     * @param int $timeout maximum time to wait between each loop in microseconds
     */
    private function wait($mh, $wait = 1000, $timeout = 1000000)
    {
        $startTime = microtime(true);

        // it sleeps until there is some activity on any of the descriptors (curl files)
        // it returns the number of descriptors (curl files that can have activity)
        $num_descriptors = curl_multi_select($mh, $timeout / 1000000);

        // if the system returns -1, it means that the wait time is unknown, and we have to decide the minimum time to wait
        // but our `$timespan` check below catches this edge case, so this `if` isn't really necessary
        if ($num_descriptors === -1) {
            usleep($wait);
        }

        $waited = (microtime(true) - $startTime);

        // This thing runs very fast, up to 1000 times for 2 urls, which wastes a lot of CPU
        // This will reduce the runs so that each interval is separated by at least minTime
        if ($waited < $wait) {
            usleep($wait - $waited);
        }
    }

    /**
     * Read completed curl handles
     *
     * @param resource $mh
     */
    private function read($mh)
    {
        // msg: The CURLMSG_DONE constant. Other return values are currently not available.
        // result: One of the CURLE_* constants. If everything is OK, the CURLE_OK will be the result.
        // handle: Resource of type curl indicates the handle which it concerns.
        while ($read = curl_multi_info_read($mh, $msgsInQueue)) {
            $ch = $read['handle'];

            if (isset($this->handles[(string)$ch]) && is_callable($this->handles[(string)$ch])) {
                call_user_func($this->handles[(string)$ch], $ch, $read['result']);
            }

            curl_multi_remove_handle($mh, $ch);
        }
    }

    /**
     * Add a curl handle to be executed in parallel
     * @param $ch
     * @param callable $callback
     */
    public function add($ch, callable $callback = null)
    {
        $this->handles[(string)$ch] = $callback;
        curl_multi_add_handle($this->mh, $ch);
    }

    /**
     * Run a loop that acts as a poor mans solution to multi-threading in php
     * @return int
     */
    public function run()
    {
        //execute the multi handle
        $prevRunning = 0;
        $running = 0;
        do {
            // $running contains the number of currently running requests
            $status = $this->exec($this->mh, $running, $this->loopTimeout);
            if ($status !== CURLM_OK) {
                return $status;
            }

            // One less is running, meaning one has finished
            if ($running < $prevRunning) {
                $this->read($this->mh);
            }

            // Still running? keep waiting...
            if ($running > 0 && $status === CURLM_OK) {
                $this->wait($this->mh, $this->loopWaitTime, $this->loopTimeout);
            }

            $prevRunning = $running;

        } while ($running > 0 && $status === CURLM_OK);

        return $status;
    }
}
