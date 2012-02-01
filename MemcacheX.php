<?php

/**
 * 
 * MemcacheX is a class that solves some problems of highload projects with
 * often changing data. It gives ability to lock records. It helps to
 * prevent simultaneous queries to database - while one process updates
 * cache, another one can't do this.
 * Each record in cache may have tags that helps to flush group of caches
 * associated with some tag.
 *  
 * @author Dmitry Menshikov <d.menshikov@creators.com.ua>
 * @version 1.0.0
 * 
 */

class MemcacheX extends Memcache {

    /**
     * Key suffix for locks
     * 
     * @var string
     */
    protected $lockSuffix = 'l_';

    /**
     * Key suffix for tags
     * 
     * @var string
     */
    protected $tagSuffix = 't_';

    /**
     * Time to wait before return old data if lock setted 
     * 
     * @var int
     */
    protected $waitTime;

    /**
     * Interval between checking for key to unlock
     * 
     * @var int
     */
    protected $waitInterval;

    /**
     * String variable for log
     * 
     * @var string
     */
    protected $log;

    /**
     * Enables or disables logging
     * 
     * @var bool
     */
    protected $logging;

    /**
     * Log filename, setted by setLog()
     * 
     * @var string
     */
    protected $logFilename;

    /**
     * MemcachedPP constructor
     * 
     * @param int[optional] $waitTime Time to wait before return old data if lock setted (in milliseconds). Default value is 3000 ms.
     * @param int[optional] $waitInterval Interval between checking for key to unlock (in milliseconds). Default value is 200 ms.
     */
    public function __construct($waitTime = 3000, $waitInterval = 200) {
        $this->waitTime = $waitTime;
        $this->waitInterval = $waitInterval;

    	/**
     	 * TODO: incoming data checking 
     	 */
    }

    public function __destruct() {
        if ($this->logging) {
            $this->flushLog();
        }
    }

    /**
     * Locks key
     * 
     * @param string $key Key that trying to lock
     * @param int[optional] $expire Lock expiration time (in seconds). Default value is 5 s.
     * @return bool Returns TRUE on success or FALSE on failure. Returns FALSE if such key already exist. 
     */
    public function lock($key, $expire = 5) {
        return parent::add($this->lockSuffix . $key, '1', 0, $expire);
    }

    /**
     * Unlocks key
     * 
     * @param string $key Key thar trying to unlock
     * @return bool Returns TRUE on success
     */
    public function unlock($key) {
        parent::delete($this->lockSuffix . $key, 0);
        return true;
    }

    /**
     * Determines is memcached unavailable
     * 
     * @return bool Returns TRUE if unavailable or FALSE if available
     */
    public function isDown() {
        if (parent::set('is_down_check', '1', 0, 1)) {
            return false;
        }

        return true;
    }

    /**
     * Gets data by key with tags support.
     * Method takes record that exist in cache even if it locked at that 
     * moment. If you want to wait record that doesn't exist but have lock
     * see 'waitForUnlock' method.
     * If tags change data becomes invalide.
     * 
     * @param string $key The key or array of keys to fetch.
     * @param mixed $flags If present, flags fetched along with the values will be written to this parameter.
     * @return mixed Returns the string associated with the key or FALSE on failure or if such key was not found.
     * 
     * @see http://www.php.net/manual/en/memcache.get.php
     */
    public function get($key, $flags = 0) {
        $data = parent::get($key, $flags);

        if ($data !== false) {
            $data = unserialize($data);
            if ((is_array($data['tags'])) && (count($data['tags']) > 0) &&
                 ($this->areTagsInvalidated($data['tags']))) {
                    return false;
            }
            return $data['data'];
        }

        return false;

     }

    /**
     * Store data at the server. If tags defined they associated with stored data.
     * Data invalidate when associated tags change. 
     * 
     * @param string $key The key that will be associated with the item.
     * @param mixed $var The variable to store. Strings and integers are stored as is, other types are stored serialized.
     * @param int[optional] $flag Use MEMCACHE_COMPRESSED to store the item compressed (uses zlib).
     * @param int[optional] $expire Expiration time of the item. If it's equal to zero, the item will never expire. You can also use Unix timestamp or a number of seconds starting from current time, but in the latter case the number of seconds may not exceed 2592000 (30 days).
     * @param array[optional] $tags Array of tags, e.g., array('tag1', 'tag2')
     * @return bool Returns TRUE on success or FALSE on failure.
     * 
     * @see http://www.php.net/manual/en/memcache.set.php
     */
    public function set($key, $var, $flag = 0, $expire = 0, $tags = null) {
        if ($tags === null) {
            $tags = array();
        } else {

            /*
             * Tags shuld be with suffix
             */

            foreach ($tags as $tag) {
                $performedTags[] = $this->tagSuffix . $tag;
            }

            /*
             * Let's get actual timestamps for tags from memcached
             */

            $tags = $this->getTagsTimestamps($performedTags);
        }

        /*
         * Data stores packed and serialized
         */

        $data = array('tags' => $tags, 'data' => $var);
        return parent::set($key, serialize($data), $flag, $expire);
    }

    /**
     * Checks key for lock
     * 
     * @param string $key Key to check.
     * @return bool Returns TRUE if key locked or FALSE if not. 
     */
    public function isLocked($key) {
        return (parent::get($this->lockSuffix . $key) !== false) ? true : false;
    }

    /**
     * Gets tag timestamp.
     * 
     * @param string $name Tag name.
     * @return mixed Returns the timestamp of tag or FALSE on failure or if such tag was not found.
     */
    public function getTag($name) {
        return parent::get($this->tagSuffix . $name);
    }

    /**
     * Sets tag timestamp
     * 
     * @param string $name Tag name.
     * @param int[optional] $expire Expiration time of the item. If it's equal to zero, the item will never expire. You can also use Unix timestamp or a number of seconds starting from current time, but in the latter case the number of seconds may not exceed 2592000 (30 days).
     * @param int[optional] $timestamp Unix timestamp. Default values is current timestamp.
     */
    public function setTag($name, $expire = 0, $timestamp = null) {
        if ($timestamp === null) $timestamp = (string) time();
        if (parent::set($this->tagSuffix . $name, $timestamp, 0, $expire)) {
            return $timestamp;
        }

        return false;
    }

    /**
     * Deletes tag.
     * 
     * @param string $name Tag name.
     * @return bool Returns TRUE on success or FALSE on error.
     */
    public function deleteTag($name) {
        return parent::delete($this->tagSuffix . $name);
    }

    /**
     * Fetchs tags with timestamps that associate with key.
     * It's not actual timestamps of tags! It's timestamps packed in cached record.
     * 
     * @param string $key The key that will be associated with the item.
     * @return mixed Returns tags associated with the key or FALSE on failure or if such key was not found.
     */
    public function getKeyTags($key) {
        $data = parent::get($key);
        if ($data !== false) {
            $data = unserialize($data);
            return $data['tags'];
        }

        return false;
    }

    /**
     * Wait for key unlocking
     * @param string $key Key of record waiting.
     * @return bool Returns TRUE if key successfully unlocked before time is up or FALSE if key stil locked. 
     */
    public function waitForUnlock($key) {
        /*
         * Milliseconds to microseconds
         */

        $sleepTime = $this->waitInterval * 1000;
        $totalSleeped = 0;
        while ($totalSleeped < $this->waitTime && $this->isLocked($key)) {
            $totalSleeped += $this->waitInterval;
            usleep($sleepTime);
        }

        if ($totalSleeped < $this->waitTime) {
            /*
             * Key unlocked
             */

            return true;
        }

        /*
         * Key stil locked
         */

        $this->log("Can't get $key. It's stil locked. Waited $totalSleeped ms.");
        return false;
    }

    /**
     * Sets logging
     * 
     * @param string $filename Path to log file
     * @param int $logLevel Determines level of logging.
     * @return bool Returns TRUE on success or FALSE if file write permission problems or etc.
     */
    public function setLog($filename, $logLevel = 0) {
        /**
         * TODO: File permissions
         */
        $this->log = '';
        $this->logging = true;
        $this->logFilename = $filename;
        return true;
    }

    /**
     * Add data to log
     * 
     * @param string $data String to log
     * @return TRUE if logging enabled or FALSE if not.
     */
    public function log($data) {
        if ($this->logging) {
            $this->log .= date('Y-m-d H:i:s') . "\t$data" . PHP_EOL;
            return true;
        }

        return false;
    }

    /**
     * Checks for tags invalidation.
     * 
     * @param array $tags Tags to check.
     * @return bool Returns TRUE if tags invalidated or FALSE if not.
     */
    protected function areTagsInvalidated($tags) {
        $actualTags = $this->getTagsTimestamps(array_keys($tags));
        $diff = array_diff_assoc($tags, $actualTags);
        if (count($diff) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Gets tags actual timestamps from memcached
     * 
     * @param array $tags Tags, e.g., array('tag1', 'tag2')
     * @return mixed Returns FALSE on error 
     */
    protected function getTagsTimestamps($tags) {
        $data = parent::get($tags);

        if ($data !== false) {
            $tagsValues = array();
            $presentTags = array();

            if (count($data) > 0) {
                foreach ($data as $tag => $timestamp) {
                    $tagsValues[$tag] = $timestamp;
                    $presentTags[] = $tag;
                }
            }

            /*
             * Maybe some tags not exist, e.g., deleted from cache.
             * So we need create that tags.
             */

            $diff = array_diff($tags, $presentTags);

            if (count($diff) > 0) {
                foreach ($diff as $tag) {
                    $tagsValues[$tag] = $this->newTag($tag);
                }
            }

            return $tagsValues;
        }

        return false;
    }

    /**
     * Sets new tag with current timestamp.
     * 
     * @param string $name Name of new tag.
     * @return mixed Returns temestamp of created tag or FALSE on error. 
     */
    public function newTag($name) {
        $timestamp = (string) time();
        if (parent::set($name, $timestamp, 0, 0)) {
            return $timestamp;
        }

        return false;
    }

    /**
     * Flushes log file to disk
     */
    protected function flushLog() {
        return @file_put_contents($this->logFilename, $this->log, FILE_APPEND);
    }
}

?>